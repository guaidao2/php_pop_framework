<?php
// EXPECT: SQLI
class Query
{
    public function whereRaw($sql)
    {
        return $this;
    }
}
$query = new Query();
$query->whereRaw('id = ' . $_GET['id']);
