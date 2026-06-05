{{-- Modale confirmation quitter --}}
<div x-show="confirmQuit" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center px-4"
     style="background:rgba(3,7,18,.9);">
    <div class="rounded-lg p-8 max-w-sm w-full text-center"
         style="background-color:#111827; border:1px solid rgba(201,168,76,.4);">
        <h3 class="font-title text-xl mb-6" style="color:#c9a84c;">
            Quitter la partie en cours ?
        </h3>
        <p class="mb-6 italic" style="color:rgba(232,224,208,0.7); font-family:'Crimson Text',serif;">
            Ta progression sera perdue.
        </p>
        <div class="flex gap-4 justify-center">
            <button @click="confirmQuit=false"
                    class="btn-secondary px-5 py-2 rounded-md font-title">
                Annuler
            </button>
            <a href="/lobby"
               class="px-5 py-2 rounded-md font-title font-semibold"
               style="background-color:#c9a84c; color:#0a0f1e;">
                Confirmer
            </a>
        </div>
    </div>
</div>
