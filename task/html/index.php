<?php

/** @var array $variables */

if(empty($_GET['ajax']))
{
  echo '<script src="/jquery-1.12.2.js"></script>';
  echo '<script src="/scripts.js"></script>';
  echo '<div id="table-container">';
}

if($variables['rows'])
{
  echo '<table border="1">';

  foreach($variables['rows'] as $row)
  {
    echo '<tr>';
    foreach($row as $cell)
    {
      echo '<td>';
      echo $cell;
      echo '</td>';
    }
    echo '</tr>';
  }

  echo '<tr>';
  echo '<td colspan="'.$variables['max'].'">';
  echo 'Итого строк: '.$variables['row_count'];
  echo '</td>';
  echo '<td>';
  echo $variables['number_total'];
  echo '</td>';
  echo '</tr>';

  echo '</table>';
}

if(empty($_GET['ajax']))
{
  echo '</div>';
  echo '<input type="button" value="Обновить" onclick="update();" />';
}

?>