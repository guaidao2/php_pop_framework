<?php
// EXPECT: XSS
echo html_entity_decode(htmlspecialchars($_GET['x']));
