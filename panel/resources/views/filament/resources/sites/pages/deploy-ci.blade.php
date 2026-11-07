@php
    $php = ! $framework->isNode();
    $build = match (true) {
        $framework === \App\Enums\Framework::Laravel => "composer install --no-dev --optimize-autoloader --no-interaction\n          npm ci && npm run build",
        $framework === \App\Enums\Framework::Symfony => "APP_ENV=prod composer install --no-dev --optimize-autoloader --no-interaction\n          APP_ENV=prod php bin/console asset-map:compile",
        $framework->isNode() => 'npm ci && npm run build',
        default => 'composer install --no-dev --optimize-autoloader --no-interaction',
    };
@endphp
<div class="space-y-4 text-sm">
    @unless ($enabled)
        <p class="rounded-lg bg-warning-50 p-3 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">Nejdřív zapněte <strong>Webhook</strong>: jeho URL a secret slouží i jako přístup pro CI.</p>
    @endunless
    <p>Do repozitáře přidejte secret <code>WSPANEL_TOKEN</code> (hodnota = <strong>Secret</strong> z okna Webhook) a jeden z workflow do <code>.github/workflows/deploy.yml</code>. Workflow počká na výsledek: když deploy selže nebo se vrátí zpět, selže i Action.</p>

    <div>
        <h3 class="font-medium">A) Release → server nasadí tag (build běží na serveru)</h3>
        <pre class="mt-2 max-h-80 overflow-auto rounded-lg bg-gray-950 p-4 font-mono text-xs text-gray-100 select-all">name: Deploy
on:
  release:
    types: [published]
concurrency: deploy
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - name: Deploy {{ '${{ github.event.release.tag_name }}' }}
        env:
          URL: {{ $base ?? 'https://…/api/deploy/…' }}
          TOKEN: {{ '${{ secrets.WSPANEL_TOKEN }}' }}
        run: |
          status_url=$(curl -fsS -X POST "$URL" -H "Authorization: Bearer $TOKEN" \
            -H 'Content-Type: application/json' -d '{"ref":"{{ '${{ github.event.release.tag_name }}' }}"}' | jq -r .status_url)
          for i in $(seq 120); do
            s=$(curl -fsS "$status_url" -H "Authorization: Bearer $TOKEN" | jq -r .status)
            echo "deploy: $s"; [ "$s" = success ] && exit 0
            [ "$s" = running ] || exit 1; sleep 5
          done; exit 1</pre>
    </div>

    <div>
        <h3 class="font-medium">B) Build v GitHub Actions → na server jde hotový balík</h3>
        <p class="text-gray-500">Server nic nebuildí: composer a npm proběhnou v CI, panel balík jen rozbalí, propojí sdílené soubory@if ($framework === \App\Enums\Framework::Laravel), spustí migrace a optimize@elseif ($framework === \App\Enums\Framework::Symfony), spustí migrace a cache:warmup@endif a přepne web. Max. 512 MB.</p>
        <pre class="mt-2 max-h-96 overflow-auto rounded-lg bg-gray-950 p-4 font-mono text-xs text-gray-100 select-all">name: Build and deploy
on:
  release:
    types: [published]
concurrency: deploy
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
@if ($php)
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
@endif
      - uses: actions/setup-node@v4
        with:
          node-version: 24
      - name: Build
        run: |
          {!! $build !!}
      - name: Package
        run: tar --exclude=.git --exclude=node_modules -czf /tmp/build.tar.gz .
      - name: Deploy
        env:
          URL: {{ $base ? $base.'/artifact' : 'https://…/api/deploy/…/artifact' }}
          TOKEN: {{ '${{ secrets.WSPANEL_TOKEN }}' }}
        run: |
          status_url=$(curl -fsS -X POST "$URL" -H "Authorization: Bearer $TOKEN" \
            -F artifact=@/tmp/build.tar.gz -F ref={{ '${{ github.ref_name }}' }} -F commit={{ '${{ github.sha }}' }} | jq -r .status_url)
          for i in $(seq 120); do
            s=$(curl -fsS "$status_url" -H "Authorization: Bearer $TOKEN" | jq -r .status)
            echo "deploy: $s"; [ "$s" = success ] && exit 0
            [ "$s" = running ] || exit 1; sleep 5
          done; exit 1</pre>
    </div>
    <p class="text-gray-500">Bez releasů? Místo <code>on: release</code> použijte <code>on: push: branches: [main]</code> a pošlete <code>{"ref":"{{ '${{ github.sha }}' }}"}</code>. Bez workflow stačí v okně Webhook zvolit „Nový release nebo tag“ a nastavit webhook v GitHubu.</p>
</div>
