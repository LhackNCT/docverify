<?php

namespace App\Http\Controllers;

use App\Mail\NouvelleDemandeAdmin;
use App\Models\DemandesCertification;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class DemandesCertificationController extends Controller
{
    /**
     * Soumet une nouvelle demande de certification.
     * POST /api/demandes-certification
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->is_certified) {
            return response()->json(['message' => 'Votre compte est déjà certifié.'], 422);
        }

        if (DemandesCertification::where('user_id', $user->id)->where('statut', 'en_attente')->exists()) {
            return response()->json(['message' => 'Vous avez déjà une demande en attente de traitement.'], 422);
        }

        $validated = $request->validate([
            'ninea'          => ['required', 'string', 'max:50'],
            'rccm'           => ['required', 'string', 'max:50'],
            'fichier_preuve' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120'],
            'message'        => ['nullable', 'string', 'max:1000'],
        ]);

        $file     = $request->file('fichier_preuve');
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path     = $file->storeAs('preuves_certification', $filename, 'local');

        $demande = DemandesCertification::create([
            'user_id'        => $user->id,
            'ninea'          => $validated['ninea'],
            'rccm'           => $validated['rccm'],
            'fichier_preuve' => $path,
            'statut'         => 'en_attente',
        ]);

        $notifMessage = "Nouvelle demande de certification de {$user->prenom} {$user->nom} ({$user->nom_institution}).";

        // Notifier les validateurs actifs
        $validateurs = User::where('role', 'validateur')->where('is_active', true)->get();

        foreach ($validateurs as $validateur) {
            Notification::create([
                'admin_id'   => $validateur->id,
                'demande_id' => $demande->id,
                'message'    => $notifMessage,
                'lu'         => false,
            ]);
            try {
                Mail::to($validateur->email)->send(new NouvelleDemandeAdmin($demande->load('user'), 'validateur'));
            } catch (\Throwable $e) {
                Log::error('Mail NouvelleDemandeAdmin failed', ['error' => $e->getMessage(), 'to' => $validateur->email]);
            }
        }

        // Fallback : notifier les admins si aucun validateur actif
        if ($validateurs->isEmpty()) {
            $admins = User::where('role', 'admin')->where('is_active', true)->get();
            foreach ($admins as $admin) {
                Notification::create([
                    'admin_id'   => $admin->id,
                    'demande_id' => $demande->id,
                    'message'    => $notifMessage,
                    'lu'         => false,
                ]);
                try {
                    Mail::to($admin->email)->send(new NouvelleDemandeAdmin($demande->load('user'), 'admin'));
                } catch (\Throwable $e) {
                    Log::error('Mail NouvelleDemandeAdmin (fallback) failed', ['error' => $e->getMessage(), 'to' => $admin->email]);
                }
            }
        }

        return response()->json([
            'message' => 'Demande de certification soumise avec succès.',
            'demande' => $demande,
        ], 201);
    }

    /**
     * Retourne la dernière demande de l'utilisateur connecté.
     * GET /api/demandes-certification/ma-demande
     */
    public function maDemande(Request $request): JsonResponse
    {
        $demande = DemandesCertification::where('user_id', $request->user()->id)
            ->latest()
            ->first();

        return response()->json($demande);
    }

    /**
     * Liste toutes les demandes.
     * GET /api/validateur/demandes
     */
    public function index(): JsonResponse
    {
        $demandes = DemandesCertification::with('user:id,nom,prenom,email,nom_institution')
            ->orderByRaw("CASE statut WHEN 'en_attente' THEN 0 ELSE 1 END")
            ->latest()
            ->get();

        return response()->json($demandes);
    }

    /**
     * Sert le fichier justificatif d'une demande.
     * GET /api/validateur/demandes/{demande}/justificatif
     */
    public function downloadJustificatif(DemandesCertification $demande)
    {
        $relative = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $demande->fichier_preuve);

        // Chercher dans private d'abord, puis dans app/ directement
        $candidates = [
            storage_path('app' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . $relative),
            storage_path('app' . DIRECTORY_SEPARATOR . $relative),
        ];

        $path = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $path = $candidate;
                break;
            }
        }

        if (!$path) {
            return response()->json(['message' => 'Fichier introuvable.'], 404);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'pdf'         => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            default       => 'application/octet-stream',
        };

        return response()->file($path, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="justificatif.' . $extension . '"',
        ]);
    }

    /**
     * Approuve une demande et certifie l'émetteur.
     * PATCH /api/validateur/demandes/{demande}/approuver
     */
    public function approuver(Request $request, DemandesCertification $demande): JsonResponse
    {
        if ($demande->statut !== 'en_attente') {
            return response()->json(['message' => 'Cette demande a déjà été traitée.'], 422);
        }

        $demande->update([
            'statut'     => 'approuvee',
            'traite_par' => $request->user()->id,
            'traite_le'  => now(),
        ]);

        $demande->user->update(['is_certified' => true]);

        try {
            Mail::to($demande->user->email)->send(new \App\Mail\DemandeApprouvee($demande->load('user')));
        } catch (\Throwable $e) {
            Log::error('Mail DemandeApprouvee failed', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => "Demande approuvée. L'émetteur est maintenant certifié.",
            'demande' => $demande->fresh()->load('user:id,nom,prenom,email,nom_institution'),
        ]);
    }

    /**
     * Refuse une demande avec un motif obligatoire.
     * PATCH /api/validateur/demandes/{demande}/refuse
     */
    public function refuse(Request $request, DemandesCertification $demande): JsonResponse
    {
        if ($demande->statut !== 'en_attente') {
            return response()->json(['message' => 'Cette demande a déjà été traitée.'], 422);
        }

        $request->validate([
            'motif_refus' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $demande->update([
            'statut'      => 'refusee',
            'motif_refus' => $request->motif_refus,
            'traite_par'  => $request->user()->id,
            'traite_le'   => now(),
        ]);

        try {
            Mail::to($demande->user->email)->send(new \App\Mail\DemandeRefusee($demande->load('user')));
        } catch (\Throwable $e) {
            Log::error('Mail DemandeRefusee failed', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Demande refusée.',
            'demande' => $demande->fresh(),
        ]);
    }
}
