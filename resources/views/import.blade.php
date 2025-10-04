@if(session('token'))
  <div id="progress" data-token="{{ session('token') }}"></div>
  <script>
    const token = document.getElementById('progress').dataset.token;
    let since = 0;
    let timer = null, inflight = false, last = '';

    async function poll(){
      if (inflight) return;
      inflight = true;
      try {
        const ctrl = new AbortController();
        const t = setTimeout(()=>ctrl.abort(), 8000); // safety
        const r = await fetch(`/import/progress/${token}?since=${since}`, { signal: ctrl.signal, headers: { 'Accept': 'application/json' }});
        clearTimeout(t);
        const j = await r.json();
        document.getElementById('stats').innerText =
          `Imported: ${j?.imported ?? 0} / ${j?.total ?? 0} | Skipped: ${j?.skipped ?? 0} | Reasons: ${JSON.stringify(j?.reasons ?? {})}`;

        since = j?.ver ?? since;

        // stop when done or when data hasn't changed for a while
        const snapshot = JSON.stringify(j);
        if (j?.done === true || snapshot === last) {
          if (j?.done === true) clearInterval(timer);
        }
        last = snapshot;
      } catch(e) {
        // ignore transient network errors
      } finally {
        inflight = false;
      }
    }

    // poll every 3s (less spammy)
    timer = setInterval(poll, 3000);
    poll();

    // pause when tab hidden (less spammy)
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) { clearInterval(timer); }
      else { timer = setInterval(poll, 3000); poll(); }
    });
  </script>
@endif


<form method="post" action="{{ route('import.store') }}" enctype="multipart/form-data">
    @csrf
    <input type="file" name="file" accept=".xlsx, .xls, .csv" required>
    <button type="submit">Import</button>
</form>

<div id="stats" style="margin-top:1rem;"></div