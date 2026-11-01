<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function store(Request $request)
    {
        $rows = DB::select("SELECT * FROM orders WHERE status = '" . $request->input('status') . "'");
        $email = $request->email;
        $users = User::query()->whereRaw("email = '$email'")->get();
        exec('convert ' . $request->input('file') . ' out.pdf');
        $cart = unserialize($request->cookie('cart'));
        $remote = file_get_contents($request->input('url'));
        User::create($request->all());
        $hash = md5($request->password);
        $safe = DB::select('SELECT * FROM orders WHERE id = ?', [$request->id]);
        dd($rows);

        return redirect($request->input('next'));
    }
}
