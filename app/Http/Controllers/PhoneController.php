<?php

namespace App\Http\Controllers;

use App\Models\UserPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PhoneController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $myPhones  = $user->phones()->latest()->get();

        $allPhones = $user->hasRole('superadmin')
            ? UserPhone::with('user')
            ->where('user_id', '!=', $user->id) 
            ->latest()
            ->get()
            : collect();


        return view('dashboard', compact('myPhones','allPhones'));
    }

    
}
