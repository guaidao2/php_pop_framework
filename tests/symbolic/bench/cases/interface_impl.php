<?php
// EXPECT: XSS
interface Renderer
{
    public function out($v);
}
class HtmlRenderer implements Renderer
{
    public function out($v)
    {
        echo $v;
    }
}
$r = new HtmlRenderer();
$r->out($_GET['x']);
