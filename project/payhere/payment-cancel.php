<?php
session_start();
// Order stays 'pending' — just send them back
header('Location: checkout.php?cancelled=1');
exit();