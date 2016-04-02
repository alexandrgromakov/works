<?php

/**
 * Страница авторизации в админку.
 */

include('options.php');

session_start();

if(!empty($_SESSION['admin']))
{
  header('Location: admin.php');
  exit;
}

if(!empty($_POST['enter']))
{
  if(empty($_POST['user']))
  {
    echo '<p>Логин пуст!</p>';
    return;
  }
  if(empty($_POST['password']))
  {
    echo '<p>Пароль пуст!</p>';
    return;
  }

  $mysqli = new mysqli('localhost',MYSQL_LOGIN,MYSQL_PASSWORD,'mydatabase','3306');

  $mysqli->query('
    create table if not exists passport(
      login blob not null,
      password blob not null,
      
      unique index(login(512))
    ) engine=InnoDB
  ');
  $mysqli->query("
    insert ignore into 
      passport 
    set 
      login='admin', 
      password='".$mysqli->escape_string(sha1('1Sg'))."'
  ");

  $resource = $mysqli->query("
    select
      password
    from
      passport
    where
      login='".$mysqli->escape_string($_POST['user'])."'
  ");

  $a_passport = $resource->fetch_assoc();
  if(!$a_passport)
  {
    echo '<p>Логин не существует!</p>';
    return;
  }

  if(sha1($_POST['password'])!==$a_passport['password'])
  {
    echo '<p>Пароль неправильный!</p>';
    return;
  }

  $mysqli->close();

  $_SESSION['admin'] = $_POST['user'];
  header('Location: admin.php');
  exit;
}

include('html/authorize.php');

?>