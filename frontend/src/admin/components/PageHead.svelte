<script lang="ts">
  import { onMount, type Snippet } from 'svelte';
  import { followLink, location } from '../../lib/router.svelte';

  let {
    eyebrow,
    title,
    back,
    actions,
  }: { eyebrow: string; title: string; back?: { href: string; label: string }; actions?: Snippet } = $props();

  let heading: HTMLHeadingElement;

  $effect(() => {
    document.title = `${title} — Belprise`;
  });

  // After in-app navigation, move focus to the new page title (screen readers).
  onMount(() => {
    if (location.navigated) heading.focus();
  });
</script>

<header class="page-head">
  {#if back}
    <a class="page-head__back" href={back.href} onclick={followLink}>← {back.label}</a>
  {/if}
  <div class="page-head__row">
    <div class="page-head__titles">
      <p class="eyebrow">// {eyebrow}</p>
      <h1 class="display page-head__title" tabindex="-1" bind:this={heading}>{title}</h1>
    </div>
    {#if actions}
      <div class="page-head__actions">{@render actions()}</div>
    {/if}
  </div>
</header>
