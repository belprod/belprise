<script lang="ts">
  import type { Snippet } from 'svelte';

  let { eyebrow, title, children }: { eyebrow: string; title: string; children: Snippet } = $props();
</script>

<div class="auth">
  <main class="auth__main">
    <p class="wordmark auth__logo">belprise<span class="wordmark__dot">.</span></p>
    <section class="card auth__card" aria-labelledby="auth-title">
      <div class="auth__head">
        <p class="eyebrow">// {eyebrow}</p>
        <h1 id="auth-title" class="display auth__title">{title}</h1>
      </div>
      {@render children()}
    </section>
  </main>
</div>

<style>
  .auth {
    display: flex;
    min-height: 100vh;
    padding: 32px 16px;
  }

  .auth__main {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 28px;
    width: 100%;
    max-width: 420px;
    margin: auto;
  }

  .auth__logo {
    font-size: 32px;
    text-align: center;
  }

  .auth__card {
    display: flex;
    flex-direction: column;
    gap: 24px;
    padding: 32px 28px;
  }

  .auth__head {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .auth__title {
    font-size: 40px;
  }

  .auth__card :global(form) {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  .auth__card :global(.btn) {
    margin-top: 8px;
  }
</style>
