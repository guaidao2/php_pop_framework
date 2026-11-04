<?php
// EXPECT: none
function safe_return()
{
    return htmlspecialchars($_GET['x']);
}
echo safe_return();
