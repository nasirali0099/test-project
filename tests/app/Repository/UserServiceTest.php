<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\UserMeta;
use App\Models\Company;
use App\Models\Department;
use App\Models\Town;
use App\Models\UserTowns;
use App\Models\UsersBlacklist;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class UserServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test creating a new user.
     *
     * @return void
     */
    public function testCreateUser()
    {
        $request = [
            'role' => env('CUSTOMER_ROLE_ID'),
            'name' => 'John Doe',
            'company_id' => '',
            'department_id' => '',
            'email' => 'john.doe@example.com',
            'dob_or_orgid' => '123456789',
            'phone' => '1234567890',
            'mobile' => '0987654321',
            'password' => 'password123',
            'consumer_type' => 'paid',
            'customer_type' => 'regular',
            'username' => 'johndoe',
            'post_code' => '12345',
            'address' => '123 Main St',
            'city' => 'Metropolis',
            'town' => 'Central City',
            'country' => 'Countryland',
            'reference' => 'yes',
            'additional_info' => 'Some additional info',
            'cost_place' => 'Cost Place',
            'fee' => '100',
            'time_to_charge' => '10',
            'time_to_pay' => '5',
            'charge_ob' => '10',
            'customer_id' => '123',
            'charge_km' => '5',
            'maximum_km' => '50',
            'translator_ex' => [1, 2, 3],
            'user_towns_projects' => [1, 2],
            'status' => '1',
        ];

        $userService = app()->make(\App\Services\UserService::class);
        $user = $userService->createOrUpdate(null, $request);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals($request['name'], $user->name);
        $this->assertEquals($request['email'], $user->email);
        $this->assertTrue(Hash::check($request['password'], $user->password));
        $this->assertEquals($request['role'], $user->user_type);
        
        // Check if the company and department were created
        $company = Company::where('name', $request['name'])->first();
        $this->assertNotNull($company);
        $this->assertEquals($company->name, $request['name']);

        $department = Department::where('name', $request['name'])->first();
        $this->assertNotNull($department);
        $this->assertEquals($department->name, $request['name']);

        // Check if user meta was created
        $userMeta = UserMeta::where('user_id', $user->id)->first();
        $this->assertNotNull($userMeta);
        $this->assertEquals($request['consumer_type'], $userMeta->consumer_type);
        $this->assertEquals($request['username'], $userMeta->username);

        // Check if blacklisted translators are added
        $blacklist = UsersBlacklist::where('user_id', $user->id)->pluck('translator_id')->all();
        $this->assertEquals($request['translator_ex'], $blacklist);

        // Check if towns were assigned
        $userTowns = UserTowns::where('user_id', $user->id)->pluck('town_id')->all();
        $this->assertEquals($request['user_towns_projects'], $userTowns);
    }

    /**
     * Test updating an existing user.
     *
     * @return void
     */
    public function testUpdateUser()
    {
        $user = User::factory()->create([
            'user_type' => env('CUSTOMER_ROLE_ID'),
            'email' => 'existing.user@example.com',
            'password' => bcrypt('password123'),
        ]);

        $request = [
            'role' => env('TRANSLATOR_ROLE_ID'),
            'name' => 'Updated Name',
            'company_id' => '',
            'department_id' => '',
            'email' => 'updated.user@example.com',
            'dob_or_orgid' => '987654321',
            'phone' => '0987654321',
            'mobile' => '1234567890',
            'password' => 'newpassword456',
            'translator_type' => 'freelance',
            'worked_for' => 'yes',
            'organization_number' => '456789',
            'gender' => 'Male',
            'translator_level' => 'Senior',
            'additional_info' => 'Updated info',
            'post_code' => '54321',
            'address' => '456 Another St',
            'address_2' => 'Apt 1',
            'town' => 'Updated Town',
            'user_language' => [4, 5],
            'new_towns' => 'New Town',
            'user_towns_projects' => [3, 4],
            'status' => '0',
        ];

        $userService = app()->make(\App\Services\UserService::class);
        $updatedUser = $userService->createOrUpdate($user->id, $request);

        $this->assertInstanceOf(User::class, $updatedUser);
        $this->assertEquals($request['name'], $updatedUser->name);
        $this->assertEquals($request['email'], $updatedUser->email);
        $this->assertTrue(Hash::check($request['password'], $updatedUser->password));
        $this->assertEquals($request['role'], $updatedUser->user_type);

        // Check if user meta was updated
        $userMeta = UserMeta::where('user_id', $updatedUser->id)->first();
        $this->assertNotNull($userMeta);
        $this->assertEquals($request['translator_type'], $userMeta->translator_type);
        $this->assertEquals($request['organization_number'], $userMeta->organization_number);

        // Check if new towns were created
        $newTown = Town::where('townname', $request['new_towns'])->first();
        $this->assertNotNull($newTown);
        $this->assertEquals($request['new_towns'], $newTown->townname);

        // Check if user languages were updated
        $userLangs = UserLanguages::where('user_id', $updatedUser->id)->pluck('lang_id')->all();
        $this->assertEquals($request['user_language'], $userLangs);

        // Check if towns were updated
        $userTowns = UserTowns::where('user_id', $updatedUser->id)->pluck('town_id')->all();
        $this->assertEquals($request['user_towns_projects'], $userTowns);
    }

    // Add more test cases as needed for other scenarios
}
