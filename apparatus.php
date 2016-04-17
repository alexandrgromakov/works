<?php

/**
 * Класс реализует работу с фискальным регистратором.
 */
class ApparatusApi
{
    /**
     * Ошибка порта.
     */
    const ERROR_PORT = 301;

    /**
     * Время смены исчерпано.
     */
    const ERROR_SHIFT = 18;

    /**
     * Аппарат не ответил за положенное время.
     */
    const ERROR_UNKNOWN = 309;

    /**
     * @var ApparatusApi
     */
    private static $instance = null;

    /**
     * Номер порта.
     *
     * @var int
     */
    private $i_port;

    /**
     * Объект аппарата. Все взаимодействия производятся через него.
     *
     * @var COM
     */
    private $o_erc;

    /**
     * Серийный номер аппарата.
     *
     * @var string
     */
    private $s_serial;

    /**
     * При создании объекта инициализирует его поля.
     *
     * @throws Exception В случае ошибок.
     */
    private function __construct()
    {
        $r_connect = mysql_connect('localhost', 'root', 'qwe');
        $r_apparatus = mysql_query('select i_port,s_serial from apparatus', $r_connect);
        $a_apparatus = mysql_fetch_assoc($r_apparatus);
        if (!$a_apparatus) {
            throw new Exception('Кассовый аппарат не настроен в Вашей программе.', 'apparatus-nx');
        }

        $this->i_port = $a_apparatus['i_port'];
        $this->s_serial = $a_apparatus['s_serial'];

        $this->o_erc = new COM('ecrmini.T400');
    }

    /**
     * При уничтожении объекта закрывает порт.
     * Если пытаемся закрыть уже закрытый порт, то это не страшно.
     */
    function __destruct()
    {
        $this->portClose();
    }

    /**
     * Закрывает порт и бросает исключение в случае ошибки аппарата.
     *
     * @param string $s_code Код ошибки.
     * @param string $s_message Текст сообщения.
     * @throws Exception В случае ошибок.
     */
    private function _exception($s_code, $s_message)
    {
        $s_command = 'close_port;';
        $this->o_erc->T400me($s_command);
        throw new Exception($s_message, $s_code);
    }

    /**
     * Возвращает сумму в денежном ящике.
     *
     * @return float Сумма в денежном ящике.
     */
    public function cashbox()
    {
        $a = $this->execute('get_cashbox_sum;');
        return $a[0];
    }

    /**
     * Регистрирует кассира.
     *
     * @param string $s_personnel ФИО кассира.
     */
    public function cashier($s_personnel)
    {
        $this->execute('write_table;3;1;' . iconv('utf-8', 'windows-1251', $s_personnel) . ';');
        $this->execute('cashier_registration;1;0;');
    }

    /**
     * Вызывается для создания объекта.
     * Открывает порт.
     * Если пытаемся открыть уже открытый порт, то это не страшно.
     *
     * @return ApparatusApi Объект для работы с фискальным регистратором.
     * @throws Exception В случае ошибок.
     */
    public static function create()
    {
        if (!isset(self::$instance)) {
            self::$instance = new ApparatusApi();
        }
        self::$instance->portOpen();

        return self::$instance;
    }

    /**
     * Печатает нулевой чек.
     *
     * @param string $s_personnel ФИО кассира.
     */
    public function emptyReceipt($s_personnel)
    {
        $this->verify();
        $this->cashier($s_personnel);
        $this->execute('print_empty_receipt;');
    }

    /**
     * Основная функция выполняющая большинство непосредственных обращений к аппарату.
     *
     * @param string $s_command Команда для выполнения.
     * @return array|null Массив составляющих ответа для тех команд, на которые аппарат возвращает ответ;
     * <tt>null</tt> для команд, ответ на которые аппарат не возвращает.
     * @throws LombardApparatusException
     */
    public function execute($s_command)
    {
        $a_result = array();
        $s_command_original = $s_command;

        // В случае некоторых ошибок пробуем посторить операцию до 4-х раз.
        for ($i = 0; $i < 4; $i++) {
            $s_command = $s_command_original;
            $this->o_erc->T400me($s_command);

            if ($s_command === $s_command_original) {
                break;
            }// Некоторые команды ничего не возвращают. Кода ошибки в них быть не может. Ну и хорошо.

            $a_result = explode(';', $s_command);
            if (!$a_result[0] || $a_result[0] != self::ERROR_UNKNOWN) {
                break;
            }// Первое значение в ответе - код ошибки. Если первое значение пустое, то - успех.
        }

        if ($a_result) {
            if ($a_result[0]) {
                switch ($a_result[0]) {
                    case self::ERROR_PORT:
                        $s_message = 'Не удалось соединиться с кассовым аппаратом. Проверьте, подключён ли кассовый аппарат.';
                        break;
                    case self::ERROR_SHIFT:
                        $s_message = 'Длительность смены превышает допустимую.';
                        break;
                    default:
                        $s_message = 'Не удалось выполнить операцию `' . $s_command_original . '`. Код ошибки: `' . $a_result[0] . '`';
                        break;
                }
                $this->_exception('operation-fail', $s_message);
            }

            return array_slice($a_result, 1);
        }

        return null;
    }

    /**
     * Закрывает COM порт.
     * Освобождает блокировку в базе данных.
     */
    public function portClose()
    {
        $r_connect = mysql_connect('localhost', 'root', 'qwe');
        mysql_query("select release_lock('apparatus-api')", $r_connect);

        $this->execute('close_port;');
    }

    /**
     * Открывает COM порт.
     * Устанавливает блокировку в базе данных для того, чтобы другой процесс не мог работать с аппаратом параллельно.
     *
     * @throws Exception В случае ошибок.
     */
    public function portOpen()
    {
        $r_connect = mysql_connect('localhost', 'root', 'qwe');
        $r_query = mysql_query("select get_lock('apparatus-api',0)", $r_connect);
        $a = mysql_fetch_row($r_query);
        if (!$a || !$a[0]) {
            throw new Exception('Кассовый аппарат может выполнять только 1 операцию за 1 раз.', 'apparatus-use');
        }

        $this->execute('open_port;' . $this->i_port . ';9600;');
    }

    /**
     * Прописывает в настройки аппарата новый товар.
     *
     * @param array $a_data Данные товара:
     * <dl><dt>int <var>i_code</var></dt><dd>Код.</dd>
     * <dt>string <var>s_title</var></dt><dd>Наименование товара.</dd></dl>
     */
    public function purchaseAdd(array $a_data)
    {
        $this->verify();
        $this->execute(
          'add_plu;' . $a_data['i_code'] . ';0;1;0;0;1;1;0.00;;' . iconv('utf-8', 'windows-1251',
            $a_data['s_title']) . ';1;'
        );
    }

    /**
     * Печатает чек продажи/возврата товара.
     *
     * @param array $a_data Входные данные:
     * <dl><dt>array[] <var>a_account</var></dt><dd>Перечень товаров в чеке. Каждый элемент - подмассив:
     * <dl><dt>float <var>f_add</var></dt><dd>Стоимость.</dd>
     * <dt>int <var>i_code</var></dt><dd>Код товара.</dd></dl>
     * </dd>
     * <dt>bool <var>is_in</var></dt><dd><tt>true</tt> - чек продажи; <tt>false</tt> - чек возврата.</dd>
     * <dt>string <var>s_personnel</var></dt><dd>ФИО сотрудника</dd></dl>
     */
    public function sale(array $a_data)
    {
        $this->verify();
        $this->cashier($a_data['s_personnel']);
        $this->execute('open_receipt;' . ($a_data['is_in'] ? '0' : '1') . ';');

        $f_sum = '0.00';
        foreach ($a_data['a_account'] as $a_account) {
            $this->execute('sale_plu;0;0;1;1;' . $a_account['i_code'] . ';' . $a_account['f_add'] . ';');
            $f_sum = a_money_add($f_sum, $a_account['f_add']);
        }
        $this->execute('pay;0;' . $f_sum . ';');
    }

    /**
     * Возвращает дату/время начала смены в формате Unix.
     *
     * @return int Дата/время начала смены.
     */
    public function shiftTime()
    {
        $a_status = $this->execute('get_status;');
        return strtotime($a_status[10] . ' ' . $a_status[11]);
    }

    /**
     * Производит служебный внос/вынос.
     *
     * @param array $a_data Входные данные:
     * <dl><dt>float <var>f_sum</var></dt><dd>Сумма.</dd>
     * <dt>bool <var>is_in</var></dt><dd><tt>true</tt> - служебный внос; <tt>false</tt> - служебный вынос.</dd>
     * <dt>string <var>s_personnel</var></dt><dd>ФИО сотрудника.</dd></dl>
     */
    public function service(array $a_data)
    {
        $this->verify();
        $this->cashier($a_data['s_personnel']);
        $this->execute('in_out;0;0;0;' . ($a_data['is_in'] ? '0' : '1') . ';' . $a_data['f_sum'] . ';;;');
    }

    /**
     * Проверяет правильность установок аппарата: серийный номер и дату на часах.
     *
     * @throws Exception В случае обнаружения ошибок.
     */
    public function verify()
    {
        $a = $this->execute('get_serial_num;');
        $s_serial = iconv('windows-1251', 'utf-8', $a[2]);
        if ($s_serial !== $this->s_serial) {
            $this->_exception(
              'serial',
              'Серия аппарата не соответствует настроенной. Текущая версия `' . $s_serial . '`; настроенная `' . $this->s_serial . '`'
            );
        }

        $a = $this->execute('get_date_time;');

        $a_time = localtime(time(), true);
        $a_time['tm_mon'] = str_pad($a_time['tm_mon'] + 1, 2, '0', STR_PAD_LEFT);
        $a_time['tm_mday'] = str_pad($a_time['tm_mday'], 2, '0', STR_PAD_LEFT);

        $s_now = $a_time['tm_mday'] . '.' . $a_time['tm_mon'] . '.' . ($a_time['tm_year'] + 1900);

        if ($a[0] !== $s_now) {
            $this->_exception(
              'time',
              'Дата на аппарате не соответствует времени на компьютере. Дата на аппарате: `' . $a[0] . '`; дата на компьютере: `' .
              $s_now . '`'
            );
        }
    }

    /**
     * Печатает z-отчёт.
     *
     * @param string $s_personnel ФИО сотрудника.
     */
    public function z($s_personnel)
    {
        $this->verify();
        $this->cashier($s_personnel);
        $this->execute('execute_report;z1;12321;');
    }
}
