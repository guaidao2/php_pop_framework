<?php
// EXPECT: LFI
include file_get_contents('php://input');
