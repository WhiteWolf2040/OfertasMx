<?php

namespace App\Http\Controllers;

use App\Models\StoreProfile;
use App\Services\StoreProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StoreProfileController extends Controller
{
    public function destroy(Request $request, StoreProfileService $profiles): Response
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'url:http,https', 'max:2048'],
        ]);
        $domain = $profiles->domainForUrl($data['url']);
        if ($domain !== null) {
            StoreProfile::query()
                ->where('user_id', $request->user()->id)
                ->where('domain', $domain)
                ->delete();
        }

        return response()->noContent();
    }
}
