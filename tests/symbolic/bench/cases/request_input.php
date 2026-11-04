<?php
// EXPECT: XSS
class Request
{
}

function handle(Request $request)
{
    echo $request->input('name');
}

handle(new Request());
