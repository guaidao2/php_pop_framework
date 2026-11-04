<?php
// EXPECT: LFI
include __DIR__ . '/pages/' . $_GET['p'];
