<?php

/**
 * Админка. Отвечает за загрузку файлов.
 */

include('options.php');

session_start();

if(empty($_SESSION['admin']))
{
  header('Location: authorize.php');
  exit;
}

if(!empty($_FILES['upload']))
{
  if(move_uploaded_file($_FILES['upload']['tmp_name'],PATH_UPLOAD.'test.txt'))
    echo '<p>Фал успешно загружен.</p>';
  else
    echo '<p>При загрузке файла возникла ошибка.</p>';
}

include('html/admin.php');

?>