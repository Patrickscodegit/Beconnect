<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\RobawsCustomerCache;
use App\Models\RobawsCustomerPortalLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form (admin/staff layout).
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Display the user's profile form (customer portal layout).
     */
    public function editCustomer(Request $request): View
    {
        $user = $request->user();
        $robawsLink = RobawsCustomerPortalLink::where('user_id', $user->id)->first();
        $robawsCache = $robawsLink
            ? RobawsCustomerCache::where('robaws_client_id', $robawsLink->robaws_client_id)->first()
            : null;

        return view('customer.profile.edit', compact('user', 'robawsCache'));
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->isCustomer()) {
            // Customers cannot change their login email — only name is updated.
            $user->name = $request->validated()['name'];
        } else {
            $user->fill($request->validated());

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
        }

        $user->save();

        $redirectRoute = $user->isCustomer() ? 'customer.profile.edit' : 'profile.edit';

        return Redirect::route($redirectRoute)->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
