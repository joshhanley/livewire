<?php

namespace Tests\Browser\Redirects;

use Illuminate\Support\Facades\View;
use Livewire\Component as BaseComponent;

class OtherComponent extends BaseComponent
{
    public function render()
    {
        return <<<'HTML'
<div x-data>
    <span dusk="other.output">Other</span>
    <button x-on:click="history.back()" type="button" dusk="other.back">Back</button>
</div>
HTML;
    }
}
