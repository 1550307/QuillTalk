<?php
session_start();
if (!isset($_SESSION['test'])) {
    $_SESSION['test'] = rand(100,999);
}
echo $_SESSION['test'];
