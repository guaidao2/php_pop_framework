<?php
// EXPECT: XXE
simplexml_load_string($_POST['xml']);
