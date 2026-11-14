import '../styles/base.css';
import { mount } from 'svelte';
import ClientApp from './ClientApp.svelte';

const target = document.getElementById('app');
if (!target) {
  throw new Error('Missing #app');
}
mount(ClientApp, { target });
