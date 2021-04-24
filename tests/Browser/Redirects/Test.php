<?php

namespace Tests\Browser\Redirects;

use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Sushi\Sushi;
use Tests\Browser\TestCase;

class Test extends TestCase
{
    public function test()
    {
        $this->browse(function ($browser) {
            Livewire::visit($browser, Component::class)
                // /**
                //  * Flashing a message shows up right away, AND
                //  * will show up if you redirect to a different
                //  * page right after.
                //  */
                // ->assertNotPresent('@flash.message')
                // ->waitForLivewire()->click('@flash')
                // ->assertPresent('@flash.message')
                // ->waitForLivewire()->click('@refresh')
                // ->assertNotPresent('@flash.message')
                // ->click('@redirect-with-flash')->waitForReload()
                // ->assertPresent('@flash.message')
                // ->waitForLivewire()->click('@refresh')
                // ->assertNotPresent('@flash.message')

                /**
                 * Livewire response is still handled event if redirecting.
                 * (Otherwise, the browser cache after a back button press
                 * won't be up to date.)
                 */
                // ->runScript('window.addEventListener("beforeunload", e => { e.preventDefault(); e.returnValue = ""; });')
                // ->dismissDialog()
                ->refresh()
                ->assertSeeIn('@redirect.blade.output', 'foo')
                ->assertSeeIn('@redirect.alpine.output', 'foo')
                ->waitForLivewire()->click('@redirect.button')
                // Ensure morphdom applies changes to page before redirect
                ->assertSeeIn('@redirect.blade.output', 'bar')
                ->assertSeeIn('@redirect.alpine.output', 'bar')
                // Currently the redirect is set to fire after 500 for experimentation purposes
                ->pause(600)
                ->assertSeeIn('@other.output', 'Other')
                ->waitForLivewire()->click('@other.back')
                ->assertSeeIn('@redirect.blade.output', 'bar')
                ->assertSeeIn('@redirect.alpine.output', 'bar')
            ;
        });
    }
}

class Post extends Model
{
    use Sushi;

    protected $guarded = [];

    protected $rows = [
        ['message' => 'foo']
    ];
}
