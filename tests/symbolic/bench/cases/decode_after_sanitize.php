<?php
// EXPECT: XSS
echo htmlspecialchars_decode(htmlspecialchars($_GET['x']));
