<?php
// EXPECT: XSS
class Store
{
    public static $value;
}
Store::$value = $_GET['x'];
echo Store::$value;
