<?php

namespace Tests\Browser\Redirects;

use Illuminate\Support\Facades\View;
use Livewire\Component as BaseComponent;

class Component extends BaseComponent
{
    public $post;

    protected $rules = ['post.message' => 'nullable'];

    public function mount()
    {
        $this->post = Post::first();
    }

    public function flashMessage()
    {
        session()->flash('message', 'some-message');
    }

    public function redirectWithFlash()
    {
        session()->flash('message', 'some-message');

        return $this->redirect('/livewire-dusk/Tests%5CBrowser%5CRedirects%5CComponent');
    }

    public function redirectPage()
    {
        $this->post->update(['message' => 'bar']);

        return $this->redirect('/livewire-dusk/Tests%5CBrowser%5CRedirects%5COtherComponent');
    }

    public function render()
    {
        return View::file(__DIR__.'/view.blade.php');
    }
}
