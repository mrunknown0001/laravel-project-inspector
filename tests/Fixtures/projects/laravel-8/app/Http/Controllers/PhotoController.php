<?php

namespace App\Http\Controllers;

class PhotoController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth')->only(['store']);
    }

    public function index() {}
    public function show($photo) {}
    public function store() {}
}
