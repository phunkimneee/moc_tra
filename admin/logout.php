<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
session_destroy();
header('Location: ../login.php');
exit();
