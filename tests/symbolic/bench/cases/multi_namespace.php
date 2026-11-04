<?php
// EXPECT: XSS
namespace A {
    class Helper
    {
        public static function out($v)
        {
            echo $v;
        }
    }
}

namespace B {
    \A\Helper::out($_GET['x']);
}
