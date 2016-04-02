<?php

echo '
  <form enctype="multipart/form-data" method="POST">
    <input type="hidden" name="MAX_FILE_SIZE" value="30000" />
    <input name="upload" type="file" />
    <input type="submit" value="Загрузить файл" />
  </form>
';

?>