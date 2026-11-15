<script lang="ts">
  import PageHead from '../components/PageHead.svelte';
  import type { Section } from '../sections';

  let { section }: { section: Section } = $props();
</script>

<PageHead eyebrow={section.eyebrow} title={section.title} />

<div class="empty">
  <p class="empty__title">Bientôt disponible</p>
  <p class="empty__text">Cette section arrive dans une prochaine version de belprise.</p>
</div>
