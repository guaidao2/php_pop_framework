<?php
// EXPECT: XSS
echo $_POST['a']['b'];
