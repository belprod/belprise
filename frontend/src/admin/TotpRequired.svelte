<script lang="ts">
  import AuthScreen from './AuthScreen.svelte';
  import RecoveryCodesList from './components/RecoveryCodesList.svelte';
  import TotpSetup from './components/TotpSetup.svelte';
  import { logout, type AdminSession } from './session';

  /** The organization asks for two-factor authentication (§5.7): set it up before anything else. */
  let { session, onDone }: { session: AdminSession; onDone: (session: AdminSession) => void } = $props();

  let enabled = $state<{ session: AdminSession; codes: string[] } | null>(null);
</script>

<AuthScreen eyebrow={session.organization?.name ?? 'Administration'} title="Double authentification">
  {#if enabled}
    <RecoveryCodesList codes={enabled.codes} email={session.user?.email ?? ''} onDone={() => enabled && onDone(enabled.session)} />
  {:else}
    <p class="intro">
      {session.organization?.name ?? 'Votre organisation'} demande la double authentification à toute l’équipe : après le mot de passe,
      un code à 6 chiffres donné par une application de votre téléphone. Mettez-la en place pour continuer.
    </p>
    <TotpSetup autoStart onEnabled={(next, codes) => (enabled = { session: next, codes })} />
    <p class="out">
      <button class="link" type="button" onclick={async () => onDone(await logout())}>Se déconnecter</button>
    </p>
  {/if}
</AuthScreen>

<style>
  .intro {
    margin-bottom: 16px;
    color: var(--text-muted);
    font-size: 14px;
    line-height: 1.5;
  }

  .out {
    margin-top: 16px;
    text-align: center;
    font-size: 13px;
  }

  .link {
    padding: 0;
    border: none;
    background: none;
    color: var(--text-muted);
    font: inherit;
    text-decoration: underline;
    cursor: pointer;
  }
</style>
