<?php

/**
 * Собрание функций.
 */

include('options.php');

/**
 * Считывает загружаемый файл с цифрами. Парсит его содержимое. Результат возвращает в формате json.
 *
 * @return string Результат парсинга загруженного файла в формате json.
 */
function file_get()
{
  if(!file_exists(PATH_UPLOAD.'test.txt'))
    return '';

  $resource = fopen(PATH_UPLOAD.'test.txt','r');

  $rows=[];
  if($resource){
    while(($row = fgets($resource))!==false){
      $digit=[];
      preg_match_all('/\d/',$row,$digit);
      $digit=$digit[0];
      rsort($digit);
      $rows[] = $digit;
    }
    fclose($resource);
  }
  return json_encode($rows);
}

/**
 * Сохраняет в базу данных содержимое файла в формате json.
 *
 * @param string $json_obj
 */
function base($json_obj)
{
  $mysqli = new mysqli('localhost',MYSQL_LOGIN,MYSQL_PASSWORD,'','3306');
  $mysqli->query('create database if not exists mydatabase');
  $mysqli->query('use mydatabase');
  $mysqli->query('
    create table if not exists test_table(
      id bigint unsigned not null auto_increment primary key,
      json_obj longblob not null,
      dt datetime not null
    ) engine=InnoDB;
  ');

  $mysqli->query('start transaction');
  $mysqli->query("
    insert into
      test_table
      (json_obj,dt)
    values
      ('".$mysqli->escape_string($json_obj)."','".$mysqli->escape_string(gmdate('Y-m-d H:i:s',time()))."')
  ");
  $mysqli->query('commit');
  $mysqli->close();
}

/**
 * Формирует данные для вывода на экран таблицы с содержимым файла, полученным из базы данных.
 *
 * @return array|bool Массив с данными для вывода таблицы в случае успеха. <tt>false</tt> в случае неудачи.
 */
function table()
{
  base(file_get());

  $mysqli = new mysqli('localhost',MYSQL_LOGIN,MYSQL_PASSWORD,'mydatabase','3306');
  $resource = $mysqli->query('
    select
      test_table.json_obj
    from
      test_table left join
      test_table as t_limit on
        t_limit.dt>test_table.dt
    where
      t_limit.id is null
  ');
  $record = $resource->fetch_assoc();
  $mysqli->close();

  if(!$record||!$record['json_obj'])
    return false;

  $json = json_decode($record['json_obj']);
  if(!$json)
    return false;

  $max = 0;
  foreach($json as $row)
  {
    if(count($row)>$max)
      $max = count($row);
  }

  if(!$max)
    return false;

  $number_total = 0;
  $rows = [];
  foreach($json as $i => $row)
  {
    $rows[$i] = [];
    $number_record = 0;
    for($j = 0;$j<=$max;$j++)
    {
      if($j==$max)
      {
        $rows[$i][$j] = $number_record;
      }
      elseif(isset($row[$j]))
      {
        $number_record++;
        $rows[$i][$j] = $row[$j];
      }
      else
      {
        $rows[$i][$j] = '';
      }
    }
    $number_total += $number_record;
  }
  
  return [
    'rows' => $rows,
    'max' => $max,
    'row_count' => count($json),
    'number_total' => $number_total
  ];
}

?>