<?php
// EXPECT: LFI
include $_GET['page'];
