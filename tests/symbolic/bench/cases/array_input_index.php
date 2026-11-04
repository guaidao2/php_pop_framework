<?php
// EXPECT: XSS
echo $_GET['a'][0];
