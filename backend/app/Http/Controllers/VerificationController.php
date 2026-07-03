<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Verification;
use App\Services\PDFService;
use App\Services\QRCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stevebauman\Location\Facades\Location;

class VerificationController extends Controller
{
    /**
     * Vérification publique d'un document via son token QR.
     * GET /api/verify/{token}
     */
    public function verify(Request $request, string $token): JsonResponse
    {
        $document = Document::where('qr_token', $token)
            ->with([
                'emetteur:id,nom,prenom,nom_institution,type_institution,is_certified',
                'verifications' => fn($q) => $q->orderBy('verified_at', 'desc')->limit(10),
            ])
            ->first();

        if (!$document) {
            return response()->json(['message' => 'Document introuvable. Le QR code est invalide.'], 404);
        }

        $statutReel   = $this->calculerStatutReel($document);
        $verification = $this->enregistrerVerification($request, $document, $statutReel);

        $pdfUrl = $document->pdf_certifie
            ? url('storage/' . $document->pdf_certifie)
            : null;

        $timeline = collect([[
            'id'          => $verification->id,
            'statut'      => $verification->statut_au_scan,
            'ip_address'  => $verification->ip_address,
            'ville'       => $verification->ville,
            'pays'        => $verification->pays,
            'verified_at' => $verification->verified_at->toIso8601String(),
            'est_courant' => true,
        ]])->merge(
            $document->verifications->map(fn($v) => [
                'id'          => $v->id,
                'statut'      => $v->statut_au_scan,
                'ip_address'  => $v->ip_address,
                'ville'       => $v->ville,
                'pays'        => $v->pays,
                'verified_at' => $v->verified_at->toIso8601String(),
                'est_courant' => false,
            ])
        )->values();

        return response()->json([
            'statut'       => $statutReel,
            'statut_label' => $this->labelStatut($statutReel),
            'statut_color' => $this->colorStatut($statutReel),

            'document' => [
                'id'               => $document->id,
                'titre'            => $document->titre,
                'type'             => $document->type,
                'hash_sha256'      => $document->hash_sha256,
                'date_emission'    => $document->date_emission?->toDateString(),
                'date_expiration'  => $document->date_expiration?->toDateString(),
                'revoked_at'       => $document->revoked_at?->toIso8601String(),
                'motif_revocation' => $document->motif_revocation,
            ],

            'emetteur' => [
                'nom'              => $document->emetteur?->nom,
                'prenom'           => $document->emetteur?->prenom,
                'nom_complet'      => $document->emetteur
                    ? $document->emetteur->prenom . ' ' . $document->emetteur->nom
                    : 'Inconnu',
                'nom_institution'  => $document->emetteur?->nom_institution,
                'type_institution' => $document->emetteur?->type_institution,
                'est_certifie'     => (bool) $document->emetteur?->is_certified,
            ],

            'pdf_certifie_url'    => $pdfUrl,
            'rapport_url'         => url('/api/verify/' . $token . '/report'),
            'verification_id'     => $verification->id,
            'verifie_le'          => $verification->verified_at->toIso8601String(),
            'total_verifications' => $document->verifications()->count() + 1,
            'timeline'            => $timeline,
        ]);
    }

    /**
     * Vérification d'intégrité par comparaison de hash SHA-256.
     * POST /api/verify/{token}/check-integrity
     */
    public function checkIntegrity(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'fichier' => ['required', 'file', 'mimetypes:application/pdf', 'max:20480'],
        ]);

        $document = Document::where('qr_token', $token)->first();
        if (!$document) {
            return response()->json(['message' => 'Document introuvable. Le QR code est invalide.'], 404);
        }

        $hashUploaded  = hash_file('sha256', $request->file('fichier')->getRealPath());

        $storageBase   = storage_path('app/public');
        $relative      = ltrim(str_replace('\\', '/', $document->pdf_certifie), '/');
        $certifiedPath = $storageBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $certifiedReal = realpath($certifiedPath);
        $storageReal   = realpath($storageBase);

        // Protection path traversal — insensible à la casse sur Windows
        if ($certifiedReal === false || $storageReal === false ||
            stripos($certifiedReal, $storageReal . DIRECTORY_SEPARATOR) !== 0) {
            return response()->json(['message' => 'Chemin de fichier invalide.'], 500);
        }

        if (!file_exists($certifiedReal)) {
            return response()->json(['message' => 'Le fichier certifié de référence est introuvable.'], 500);
        }

        $hashReference = hash_file('sha256', $certifiedReal);
        $integre       = hash_equals($hashReference, $hashUploaded);

        if ($integre) {
            return response()->json([
                'integre'        => true,
                'message'        => 'Le document est intègre. Les hashs correspondent.',
                'hash_fourni'    => $hashUploaded,
                'hash_reference' => $hashReference,
            ]);
        }

        return response()->json([
            'integre'        => false,
            'statut'         => 'falsifie',
            'message'        => 'Attention : ce document a été modifié après sa certification.',
            'hash_fourni'    => $hashUploaded,
            'hash_reference' => $hashReference,
        ], 422);
    }

    /**
     * Génère et télécharge un rapport PDF de vérification.
     * GET /api/verify/{token}/report
     */
    public function downloadReport(string $token, QRCodeService $qrCodeService, PDFService $pdfService)
    {
        $document = Document::where('qr_token', $token)
            ->with('emetteur:id,nom,prenom,nom_institution,type_institution')
            ->first();

        if (!$document) {
            return response()->json(['message' => 'Document introuvable. Le QR code est invalide.'], 404);
        }

        $statutReel  = $this->calculerStatutReel($document);
        $verifyUrl   = rtrim(config('app.frontend_url'), '/') . '/verify/' . $token;
        $qrPngBinary = $qrCodeService->renderQrPng($verifyUrl, 200);
        $qrSvg       = 'data:image/png;base64,' . base64_encode($qrPngBinary);

        $meta = [
            'titre'             => $document->titre,
            'type'              => $document->type,
            'statut'            => $statutReel,
            'hash_sha256'       => $document->hash_sha256,
            'date_emission'     => $document->date_emission?->toDateString(),
            'date_expiration'   => $document->date_expiration?->toDateString(),
            'motif_revocation'  => $document->motif_revocation,
            'revoked_at'        => $document->revoked_at?->toIso8601String(),
            'emetteur'          => $document->emetteur
                ? $document->emetteur->prenom . ' ' . $document->emetteur->nom
                : 'Inconnu',
            'institution'       => $document->emetteur?->nom_institution ?? 'N/A',
            'qr_token'          => $token,
            'rapport_genere_le' => now()->toIso8601String(),
            'nb_verifications'  => $document->verifications()->count(),
        ];

        $reportPath = $pdfService->generateVerificationReport($qrSvg, $meta);

        return response()->download($reportPath, 'rapport_verification_' . $token . '.pdf', [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    // ── Helpers privés ────────────────────────────────────────────────────

    private function calculerStatutReel(Document $document): string
    {
        if ($document->statut === 'revoque') return 'revoque';
        if ($document->date_expiration && $document->date_expiration->isPast()) return 'expire';
        return 'valide';
    }

    private function labelStatut(string $statut): string
    {
        return match ($statut) {
            'valide'  => 'Document valide',
            'revoque' => 'Document révoqué',
            'expire'  => 'Document expiré',
            default   => 'Statut inconnu',
        };
    }

    private function colorStatut(string $statut): string
    {
        return match ($statut) {
            'valide'  => 'green',
            'revoque' => 'red',
            'expire'  => 'orange',
            default   => 'gray',
        };
    }

    private function enregistrerVerification(Request $request, Document $document, string $statut): Verification
    {
        $ip    = $request->ip();
        $ville = null;
        $pays  = null;

        try {
            $position = Location::get($ip);
            if ($position) {
                $ville = $position->cityName    ?: null;
                $pays  = $position->countryName ?: null;
            }
        } catch (\Throwable) {
            // Géolocalisation non bloquante
        }

        return Verification::create([
            'document_id'    => $document->id,
            'ip_address'     => $ip,
            'ville'          => $ville,
            'pays'           => $pays,
            'statut_au_scan' => $statut,
            'verified_at'    => now(),
        ]);
    }
}
