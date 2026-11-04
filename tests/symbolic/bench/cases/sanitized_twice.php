<?php
// EXPECT: none
echo htmlspecialchars(htmlspecialchars($_GET['x']));
