<script lang="ts">
  /** The ten recovery codes, shown once (§5.7): to print, copy or save before going on. */
  let { codes, email, onDone }: { codes: string[]; email: string; onDone: () => void } = $props();

  let copied = $state(false);
  const text = $derived(
    `Codes de secours belprise — ${email}\nChaque code ne sert qu’une fois, à la place du code de l’application.\n\n${codes.join('\n')}\n`,
  );
  const fileUrl = $derived(URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' })));
  $effect(() => {
    const url = fileUrl;
    return () => URL.revokeObjectURL(url);
  });

  async function copy(): Promise<void> {
    await navigator.clipboard.writeText(text);
    copied = true;
  }
</script>

<div class="box codes" role="status">
  <p>
    <strong>Vos 10 codes de secours.</strong> Si vous perdez votre téléphone, chacun remplace une fois le code de l’application.
    Gardez-les à l’abri (gestionnaire de mots de passe, papier) : <strong>ils ne seront plus affichés</strong>.
  </p>
  <ul class="codes__list mono">
    {#each codes as code (code)}<li>{code}</li>{/each}
  </ul>
  <div class="form-actions">
    <button class="btn btn--small" type="button" onclick={copy}>{copied ? 'Copiés' : 'Copier'}</button>
    <a class="btn btn--small" href={fileUrl} download="belprise-codes-de-secours.txt">Télécharger (.txt)</a>
    <button class="btn btn--small btn--primary" type="button" onclick={onDone}>J’ai noté mes codes</button>
  </div>
</div>

<style>
  .codes p {
    font-size: 13.5px;
    line-height: 1.5;
  }

  .codes__list {
    display: grid;
    grid-template-columns: repeat(2, max-content);
    gap: 6px 32px;
    margin: 12px 0;
    padding: 0;
    list-style: none;
    font-size: 15px;
  }
</style>
