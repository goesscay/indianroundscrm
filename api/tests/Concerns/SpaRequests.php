<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Simulates the Next.js SPA: same-origin Referer so Sanctum treats requests as stateful,
 * and a per-"browser" cookie jar so real login sessions persist between requests.
 */
trait SpaRequests
{
    /** @var array<string, array<string,string>> */
    protected array $jars = [];

    protected string $currentBrowser = 'main';

    protected ?string $lastBrowser = null;

    protected function spa(?string $browser = null): static
    {
        $browser ??= $this->currentBrowser;
        $this->currentBrowser = $browser;
        if ($this->lastBrowser !== $browser) {
            // the in-process session store is shared; clear it when switching to another simulated browser
            $this->app['session']->driver()->flush();
            $this->lastBrowser = $browser;
        }
        $this->app['auth']->forgetGuards(); // drop the cached guard user between simulated requests
        $this->disableCookieEncryption()->withCredentials(); // we replay the browser's cookies exactly as received
        $this->defaultCookies = $this->jars[$browser] ?? [];

        return $this->withHeaders(['Referer' => 'http://localhost:3000', 'Origin' => 'http://localhost:3000', 'Accept' => 'application/json']);
    }

    protected function remember(TestResponse $res): TestResponse
    {
        foreach ($res->headers->getCookies() as $c) {
            if ($c->getValue() === null || $c->getExpiresTime() !== 0 && $c->getExpiresTime() < time()) {
                unset($this->jars[$this->currentBrowser][$c->getName()]);
            } else {
                $this->jars[$this->currentBrowser][$c->getName()] = $c->getValue(); // raw (encrypted) value, like a browser
            }
        }

        return $res;
    }

    protected function login(string $email, string $password, string $browser = 'main'): TestResponse
    {
        return $this->remember($this->spa($browser)->postJson('/api/auth/login', ['email' => $email, 'password' => $password]));
    }

    protected function loginAs(User $user, string $browser = 'main', string $password = 'Passw0rd!x'): TestResponse
    {
        $res = $this->login($user->email, $password, $browser);
        $res->assertOk();

        return $res;
    }

    protected function api(string $method, string $uri, array $data = [], string $browser = 'main'): TestResponse
    {
        return $this->remember($this->spa($browser)->json($method, $uri, $data));
    }
}
