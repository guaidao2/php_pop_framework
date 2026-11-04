<?php
// EXPECT: PATH_TRAVERSAL
move_uploaded_file($_FILES['f']['tmp_name'], $_GET['dest']);
