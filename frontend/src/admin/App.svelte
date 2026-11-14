<script lang="ts">
  import { setCsrfToken, setUnauthorizedHandler } from '../lib/api';
  import { readBoot } from '../lib/boot';
  import { location } from '../lib/router.svelte';
  import { current } from './current.svelte';
  import InvitationPage from './InvitationPage.svelte';
  import ResetPage from './ResetPage.svelte';
  import Layout from './Layout.svelte';
  import Login from './Login.svelte';
  import Setup from './Setup.svelte';
  import SuperApp from './super/SuperApp.svelte';
  import TotpRequired from './TotpRequired.svelte';
  import { SIGNED_OUT, type AdminSession } from './session';
  import { applyTheme } from './theme';

  interface SuperSession {
    authenticated: boolean;
    name: string | null;
    codeRequired: boolean;
    csrf: string;
  }

  const boot = readBoot<{ page: 'admin'; session: AdminSession } | { page: 'superadmin'; session: SuperSession }>();
  setCsrfToken(boot.session.csrf);
  const superadmin = boot.page === 'superadmin' ? boot.session : null;

  let session = $state(boot.page === 'admin' ? boot.session : SIGNED_OUT);
  $effect(() => {
    current.session = session;
  });
  // The organization's colours follow the session (sign-in, sign-out, settings saved).
  $effect(() => {
    if (!superadmin) applyTheme(session.theme);
  });

  // Expired session: back to the login screen, on the same page.
  setUnauthorizedHandler(() => {
    session = { ...SIGNED_OUT, csrf: session.csrf };
  });

  function update(next: AdminSession): void {
    session = next;
  }

  const invitation = $derived(/^\/invitation\/([A-Za-z0-9_-]{43})$/.exec(location.path)?.[1] ?? null);
  const resetToken = $derived(/^\/reinitialisation\/([A-Za-z0-9_-]{43})$/.exec(location.path)?.[1] ?? null);
</script>

{#if superadmin}
  <SuperApp initial={superadmin} />
{:else if resetToken}
  <ResetPage token={resetToken} />
{:else if invitation}
  <InvitationPage token={invitation} onDone={update} />
{:else if session.setupRequired}
  <Setup onDone={update} />
{:else if !session.authenticated}
  {#key session.totpRequired}
    <Login onDone={update} codeStep={session.totpRequired === true} />
  {/key}
{:else if session.totpSetupRequired}
  <TotpRequired {session} onDone={update} />
{:else}
  <Layout {session} onLogout={update} onSession={update} />
{/if}
