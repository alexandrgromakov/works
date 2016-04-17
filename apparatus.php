<?php

/**
 * Implements work with fiscal registrar.
 */
class ApparatusApi
{
    /**
     * Port error.
     */
    const ERROR_PORT = 301;

    /**
     * Shift time is ower.
     */
    const ERROR_SHIFT = 18;

    /**
     * Device did not respond within allotted time.
     */
    const ERROR_UNKNOWN = 309;

    /**
     * @var ApparatusApi
     */
    private static $instance = null;

    /**
     * Port number.
     *
     * @var int
     */
    private $i_port;

    /**
     * Object of device. All communications are made through it.
     *
     * @var COM
     */
    private $o_erc;

    /**
     * Device serial number.
     *
     * @var string
     */
    private $s_serial;

    /**
     * When creating object initializes its fields.
     *
     * @throws Exception On errors.
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
     * When object is destroyed, closes port.
     * If you try to close already closed port, it is not problem.
     */
    function __destruct()
    {
        $this->portClose();
    }

    /**
     * Closes port and throws exception when device error.
     *
     * @param string $s_code Error code.
     * @param string $s_message Message text.
     * @throws Exception Always.
     */
    private function _exception($s_code, $s_message)
    {
        $s_command = 'close_port;';
        $this->o_erc->T400me($s_command);
        throw new Exception($s_message, $s_code);
    }

    /**
     * Returns amount in cashbox.
     *
     * @return float Amount in cashbox.
     */
    public function cashbox()
    {
        $a = $this->execute('get_cashbox_sum;');
        return $a[0];
    }

    /**
     * Registers cashier.
     *
     * @param string $s_personnel Cashier full name.
     */
    public function cashier($s_personnel)
    {
        $this->execute('write_table;3;1;' . iconv('utf-8', 'windows-1251', $s_personnel) . ';');
        $this->execute('cashier_registration;1;0;');
    }

    /**
     * Is called to create object.
     * Opens port.
     * If you try to open already opened port, it is not problem.
     *
     * @return ApparatusApi Object to work with fiscal registrar.
     * @throws Exception On error.
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
     * Prints zero bill.
     *
     * @param string $s_personnel Cashier full name.
     */
    public function emptyReceipt($s_personnel)
    {
        $this->verify();
        $this->cashier($s_personnel);
        $this->execute('print_empty_receipt;');
    }

    /**
     * Main function which performing most direst appeals to device.
     *
     * @param string $s_command Command to execute.
     * @return array|null Array of response components for commands, for which device returns result;
     * <tt>null</tt> for commands, for which device does not return result.
     * @throws LombardApparatusException On errors.
     */
    public function execute($s_command)
    {
        $a_result = array();
        $s_command_original = $s_command;

        // In case of some errors trying to repeat operation of up 4 times.
        for ($i = 0; $i < 4; $i++) {
            $s_command = $s_command_original;
            $this->o_erc->T400me($s_command);

            if ($s_command === $s_command_original) {
                break;
            }// Some commands do not return anything. Its can not return error code. So, well.

            $a_result = explode(';', $s_command);
            if (!$a_result[0] || $a_result[0] != self::ERROR_UNKNOWN) {
                break;
            }// First value in response is error code. If first value is empty, it is success.
        }

        if ($a_result) {
            if ($a_result[0]) {
                switch ($a_result[0]) {
                    case self::ERROR_PORT:
                        $s_message = 'Can not connect to device. Check if device is plugged.';
                        break;
                    case self::ERROR_SHIFT:
                        $s_message = 'Duration of shift exceeds allowable duration.';
                        break;
                    default:
                        $s_message = 'Can ton perform operation `' . $s_command_original . '`. Error code: `' . $a_result[0] . '`';
                        break;
                }
                $this->_exception('operation-fail', $s_message);
            }

            return array_slice($a_result, 1);
        }

        return null;
    }

    /**
     * Closes COM port.
     * Releases lock in database.
     */
    public function portClose()
    {
        $r_connect = mysql_connect('localhost', 'root', 'qwe');
        mysql_query("select release_lock('apparatus-api')", $r_connect);

        $this->execute('close_port;');
    }

    /**
     * Opens COM port.
     * Sets lock in database to other process may not work in parallel with device.
     *
     * @throws Exception On errors.
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
     * Sets up new goods into device settings.
     *
     * @param array $a_data Product data:
     * <dl><dt>int <var>i_code</var></dt><dd>Code.</dd>
     * <dt>string <var>s_title</var></dt><dd>Product name.</dd></dl>
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
     * Prints bill of goods sale/return.
     *
     * @param array $a_data Input data:
     * <dl><dt>array[] <var>a_account</var></dt><dd>List of goods in bill. Every element - sub array:
     * <dl><dt>float <var>f_add</var></dt><dd>Cost.</dd>
     * <dt>int <var>i_code</var></dt><dd>Code of product.</dd></dl>
     * </dd>
     * <dt>bool <var>is_in</var></dt><dd><tt>true</tt> - sale bill; <tt>false</tt> - return bill.</dd>
     * <dt>string <var>s_personnel</var></dt><dd>Cashier full name.</dd></dl>
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
     * Returns date/time of shift start in Unix format.
     *
     * @return int Date/time of shift start.
     */
    public function shiftTime()
    {
        $a_status = $this->execute('get_status;');
        return strtotime($a_status[10] . ' ' . $a_status[11]);
    }

    /**
     * Performs service deposit/removal.
     *
     * @param array $a_data Input data:
     * <dl><dt>float <var>f_sum</var></dt><dd>Amount.</dd>
     * <dt>bool <var>is_in</var></dt><dd><tt>true</tt> - service deposit; <tt>false</tt> - service removal.</dd>
     * <dt>string <var>s_personnel</var></dt><dd>Cashier full name.</dd></dl>
     */
    public function service(array $a_data)
    {
        $this->verify();
        $this->cashier($a_data['s_personnel']);
        $this->execute('in_out;0;0;0;' . ($a_data['is_in'] ? '0' : '1') . ';' . $a_data['f_sum'] . ';;;');
    }

    /**
     * Check correctness of device settings: serial number an date in clock.
     *
     * @throws Exception On errors.
     */
    public function verify()
    {
        $a = $this->execute('get_serial_num;');
        $s_serial = iconv('windows-1251', 'utf-8', $a[2]);
        if ($s_serial !== $this->s_serial) {
            $this->_exception(
              'serial',
              'Series of device does not correspond to configured. Current series `' . $s_serial . '`; configured `' . $this->s_serial . '`'
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
              'Date on device does not correspond to date on computer. Date on device: `' . $a[0] . '`; date on computer: `' .
              $s_now . '`'
            );
        }
    }

    /**
     * Prints z-report.
     *
     * @param string $s_personnel Cashier full name.
     */
    public function z($s_personnel)
    {
        $this->verify();
        $this->cashier($s_personnel);
        $this->execute('execute_report;z1;12321;');
    }
}
