<?php
// EXPECT: XSS
echo $_FILES['f']['name'];
