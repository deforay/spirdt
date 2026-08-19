<script setup lang="ts">
import { PhChartBar, PhEye, PhEyeSlash, PhWarningCircle } from '@phosphor-icons/vue'
import { computed, ref } from 'vue'

import { ApiError } from '@/api/client'
import LocaleSwitcher from '@/components/LocaleSwitcher.vue'
import { signIn } from '@/auth/login'
import { t } from '@/i18n'

/**
 * Sign in.
 *
 * The organisation field appears only once the server has asked for it. Most
 * installations have one organisation, and a field that is nearly always blank
 * is a field people fill in wrongly.
 *
 * Two layouts, one form. On a phone it is the form and nothing else, because
 * that is the screen an assessor signs in on at the start of a visit. Past
 * 1024px a navy panel carries the name of the programme beside it — that is
 * the width a stakeholder sees the app at, and this is the first screen they
 * see. The panel holds no controls, so nothing is lost when it is not there.
 *
 * THE PANEL IS NAVY, NOT ACCENT, and that is the palette's own rule rather
 * than a preference: the accent is for marks — a link, an icon, a button —
 * and chrome is "where a navy that heavy belongs: behind white text, at the
 * size of a bar". Half a screen of accent is the largest field of it in the
 * application, and at that size a colour meant to draw the eye to a control
 * stops meaning anything and just shouts. Navy also lets the two things on
 * the panel that should carry — the brass rule and the brass level chip —
 * actually carry.
 */

const emit = defineEmits<{ signedIn: [] }>()

const email = ref('')
const password = ref('')
const organization = ref('')
const needsOrganization = ref(false)
const error = ref('')
const busy = ref(false)

/**
 * Show the password.
 *
 * This installation hands out generated passwords twenty-four characters
 * long, and the first thing anybody does with one is type it into a phone on
 * a bench. Typing that blind, twice, is the difference between signing in and
 * giving up. Off by default, because the other place it gets typed is a
 * shared tablet with somebody watching.
 */
const revealed = ref(false)

const canSubmit = computed(
    () => email.value.trim() !== '' && password.value !== '' && !busy.value,
)

async function submit() {
    if (!canSubmit.value) {
        return
    }

    busy.value = true
    error.value = ''

    try {
        await signIn({
            email: email.value.trim(),
            password: password.value,
            organization: organization.value.trim() || undefined,
        })

        emit('signedIn')
    } catch (caught) {
        if (caught instanceof ApiError && caught.status === 409) {
            needsOrganization.value = true
        }

        error.value = caught instanceof Error ? caught.message : t('signIn.failed')
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div class="flex min-h-screen bg-ground lg:items-stretch">
        <!--
            The panel is decoration in the strict sense: nothing here can be
            acted on, and it is hidden below 1024px rather than stacked, so a
            phone opens straight onto the form. aria-hidden for the same
            reason — the wordmark beside the form would otherwise be announced
            twice.
        -->
        <aside
            aria-hidden="true"
            class="relative hidden w-[46%] max-w-[560px] shrink-0 items-center justify-center overflow-hidden bg-chrome px-12 py-14 text-chrome-ink lg:flex"
        >
            <!--
                Geometry, not a picture.

                A flat navy rectangle half a screen wide reads as an unpainted
                wall, and the answer is not an illustration: this is installed
                by ministries who put their own name on it, and a stock image
                is the first thing that has to come out when they do. A ruled
                grid fading into the corners is texture rather than a second
                colour language — nothing to localise, nothing to load, and it
                cannot be the thing that fails on a slow connection.
            -->
            <span
                aria-hidden="true"
                class="pointer-events-none absolute -right-10 -top-10 size-[380px]"
                style="
                    background-image:
                        repeating-linear-gradient(to right, rgba(255,255,255,0.10) 0 1px, transparent 1px 52px),
                        repeating-linear-gradient(to bottom, rgba(255,255,255,0.10) 0 1px, transparent 1px 52px);
                    mask-image: radial-gradient(closest-side, black, transparent);
                    -webkit-mask-image: radial-gradient(closest-side, black, transparent);
                "
            ></span>
            <span
                aria-hidden="true"
                class="pointer-events-none absolute -bottom-10 -left-10 size-[380px]"
                style="
                    background-image:
                        repeating-linear-gradient(to right, rgba(255,255,255,0.10) 0 1px, transparent 1px 52px),
                        repeating-linear-gradient(to bottom, rgba(255,255,255,0.10) 0 1px, transparent 1px 52px);
                    mask-image: radial-gradient(closest-side, black, transparent);
                    -webkit-mask-image: radial-gradient(closest-side, black, transparent);
                "
            ></span>

            <!-- One accent light, so the panel still says which application
                 this is without spending half a screen on the colour. -->
            <span
                class="pointer-events-none absolute left-1/2 top-1/3 h-[440px] w-[440px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-accent/25 blur-3xl"
            ></span>

            <!--
                Centred, and short. Everything here is one idea — what this is
                and what it produces — and a column of blocks pinned to the top
                and bottom of the panel made it look like a page with its
                middle missing.
            -->
            <div class="relative flex max-w-[22rem] flex-col items-center text-center">
                <span
                    class="flex size-12 items-center justify-center rounded-card bg-accent text-accent-ink"
                >
                    <PhChartBar :size="25" weight="bold" />
                </span>

                <span class="eyebrow mt-5 text-chrome-ink/60">SPI-RDT</span>

                <p class="mt-3 text-[30px] font-extrabold leading-[1.15]">
                    {{ t('signIn.tagline') }}
                </p>

                <!-- The brass rule, cut to a deliberate length rather than to
                     the words. It is the mark this app puts under the name of
                     a thing of record, and on a centred composition a rule
                     that measures the longest line reads as an accident. -->
                <span class="mt-6 block h-0.5 w-16 rounded-full bg-brass-fill"></span>

                <p class="mt-6 text-[15px] leading-relaxed text-chrome-ink/65">
                    {{ t('signIn.panelNote') }}
                </p>
            </div>
        </aside>

        <div class="flex min-h-screen w-full flex-col justify-center px-5 py-10 lg:px-16">
            <div class="mx-auto w-full max-w-[430px]">
                <!--
                    The mark, above the form rather than beside it.
                    Every other screen in the application opens with this tile
                    at the top left, and a sign-in page that does not wear it
                    is a page somebody has to read before believing they are in
                    the right place. On a desk the panel carries it too, and
                    that repetition is the point: the panel is hidden from
                    assistive technology, so this is the copy that exists.
                -->
                <header class="mb-6 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <span
                            class="flex size-11 items-center justify-center rounded-card bg-accent text-accent-ink lg:hidden"
                        >
                            <PhChartBar :size="23" weight="bold" aria-hidden="true" />
                        </span>
                        <h1 class="mt-3.5 text-[30px] font-extrabold tracking-[-0.02em] lg:mt-0">
                            SPI-RDT
                        </h1>
                        <p class="mt-1 text-[16px] leading-snug text-label-2">
                            {{ t('signIn.subtitle') }}
                        </p>
                    </div>
                    <div class="mt-1.5 shrink-0"><LocaleSwitcher /></div>
                </header>

                <!--
                    The form sits on a card. It used to float on the page,
                    which on a wide screen is a handful of controls adrift in
                    the middle of nothing; the card gives the eye an edge to
                    start at and makes the page look built rather than
                    unfinished. Below 640px it drops the border and the shadow
                    — a card inset inside a phone screen is a frame around
                    almost the whole viewport, which just wastes width.
                -->
                <form
                    class="flex flex-col gap-4 sm:rounded-surface sm:border sm:border-hairline sm:bg-surface sm:p-7 sm:shadow-surface"
                    @submit.prevent="submit"
                >
                    <!-- Label above field, as everywhere else. The row idiom
                         this replaces put a fixed 76px gutter before every
                         input, which is a quarter of a phone's width spent on
                         the word "Email". -->
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[14px] font-medium text-label-2">
                            {{ t('signIn.email') }}
                        </span>
                        <input
                            v-model="email"
                            type="email"
                            autocomplete="username"
                            inputmode="email"
                            autocapitalize="off"
                            spellcheck="false"
                            class="field"
                            placeholder="you@example.org"
                        />
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-[14px] font-medium text-label-2">
                            {{ t('signIn.password') }}
                        </span>

                        <!-- The reveal sits inside the field's own box so the
                             field keeps the full width it needs; the input is
                             padded on the right by exactly the button. -->
                        <span class="relative flex">
                            <input
                                v-model="password"
                                :type="revealed ? 'text' : 'password'"
                                autocomplete="current-password"
                                class="field pr-12"
                                placeholder="••••••••••••"
                            />
                            <button
                                type="button"
                                :aria-label="revealed ? t('signIn.hidePassword') : t('signIn.showPassword')"
                                :aria-pressed="revealed"
                                class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-card text-label-3 transition-colors hover:text-label"
                                @click="revealed = !revealed"
                            >
                                <component
                                    :is="revealed ? PhEyeSlash : PhEye"
                                    :size="18"
                                    aria-hidden="true"
                                />
                            </button>
                        </span>
                    </label>

                    <label v-if="needsOrganization" class="flex flex-col gap-1.5">
                        <span class="text-[14px] font-medium text-label-2">
                            {{ t('signIn.organization') }}
                        </span>
                        <input
                            v-model="organization"
                            type="text"
                            autocapitalize="off"
                            spellcheck="false"
                            class="field"
                            placeholder="demo"
                        />
                        <!-- Said here rather than left to be guessed. The
                             field appears because the server asked for it, and
                             somebody seeing it for the first time does not
                             know it means "which of the organisations sharing
                             your address". -->
                        <span class="text-[13px] leading-snug text-label-3">
                            {{ t('signIn.organizationHint') }}
                        </span>
                    </label>

                    <!--
                        A block rather than a red line. Sign-in fails for one
                        of two reasons — wrong credentials, or the device is
                        offline — and both need reading rather than glancing
                        at. role="alert" so it is announced when it appears,
                        which for a screen reader is the only way to know the
                        button did anything.
                    -->
                    <p
                        v-if="error !== ''"
                        role="alert"
                        class="flex items-start gap-2 rounded-card bg-no-soft px-3.5 py-3 text-[14px] font-medium leading-snug text-no"
                    >
                        <PhWarningCircle :size="17" class="mt-px shrink-0" aria-hidden="true" />
                        {{ error }}
                    </p>

                    <button
                        type="submit"
                        :disabled="!canSubmit"
                        class="mt-1 min-h-12 rounded-card bg-accent px-5 text-[17px] font-semibold text-accent-ink transition-colors hover:bg-accent-hover disabled:opacity-40"
                    >
                        {{ busy ? t('signIn.submitting') : t('signIn.submit') }}
                    </button>
                </form>

                <!-- Offline-first is the whole premise of the assessor app,
                     and this is the one screen where it is not true: a token
                     has to be fetched at least once. Saying so here is
                     cheaper than the support call that starts "it worked
                     yesterday in the field". -->
                <p class="mt-5 text-center text-[13px] leading-snug text-label-3">
                    {{ t('signIn.onlineNote') }}
                </p>
            </div>
        </div>
    </div>
</template>
