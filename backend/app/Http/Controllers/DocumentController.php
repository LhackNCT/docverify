<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\HashService;
use App\Services\QRCodeService;
use App\Services\PDFService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    /**
     * Certifie un nouveau document : hash SHA-256, génération QR, tamponnage PDF.
     * POST /api/documents
     */
    public function store(Request $request, HashService $hashService, QRCodeService $qrCodeService, PDFService $pdfService): JsonResponse
    {
        $validated = $request->validate([
            'titre'            => ['required', 'string', 'max:255'],
            'type'             => ['required', 'string', 'in:diplome,attestation,certificat,contrat,autre,offre_emploi,appel_offres,communique,decision,convention,rapport'],
            'fichier_original' => ['required', 'file', 'mimetypes:application/pdf', 'max:20480'],
            'date_emission'    => ['required', 'date'],
            'date_expiration'  => ['nullable', 'date', 'after:date_emission'],
            'qr_positions'     => ['nullable', 'string'],
            'qr_size_mm'       => ['nullable', 'integer', 'min:15', 'max:60'],
        ]);

        $emetteur = $request->user();

        if (!$emetteur->is_certified) {
            return response()->json([
                'message' => 'Votre institution doit être certifiée avant de pouvoir certifier des documents.',
            ], 403);
        }

        $typesInstitution = ['offre_emploi', 'appel_offres', 'communique', 'decision', 'convention', 'rapport'];
        if (in_array($validated['type'], $typesInstitution) && !$emetteur->is_certified) {
            return response()->json([
                'message' => 'Votre institution doit être certifiée pour émettre ce type de document.',
            ], 403);
        }

        $uploaded = $request->file('fichier_original');

        // 1. Hash du fichier original
        $hash = $hashService->hashSha256($uploaded);

        // 2. Vérifier doublon
        $existant = Document::where('hash_sha256', $hash)->first();
        if ($existant) {
            return response()->json([
                'message'     => 'Ce document a déjà été certifié.',
                'document_id' => $existant->id,
                'qr_token'    => $existant->qr_token,
                'certifie_le' => $existant->created_at?->toDateString(),
            ], 422);
        }

        // 3. Génération du token et du QR
        $token     = $qrCodeService->generateToken();
        $verifyUrl = rtrim(config('app.frontend_url'), '/') . '/verify/' . $token;
        $qrPng     = $qrCodeService->renderQrPng($verifyUrl, 260);

        // 4. Sauvegarde du fichier original
        $filename         = Str::uuid() . '.pdf';
        $originalDir      = storage_path('app/originals');
        if (!is_dir($originalDir)) {
            mkdir($originalDir, 0755, true);
        }
        $uploaded->move($originalDir, $filename);
        $originalAbsolute = $originalDir . DIRECTORY_SEPARATOR . $filename;
        $originalPath     = 'originals/' . $filename;

        // 5. Parsing des positions QR
        $qrPositions = [];
        if (!empty($validated['qr_positions'])) {
            $decoded = json_decode($validated['qr_positions'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $p) {
                    if (isset($p['page']) && is_numeric($p['page'])) {
                        $qrPositions[(int)$p['page']] = [
                            'x_mm' => isset($p['x_mm']) && is_numeric($p['x_mm']) ? (float)$p['x_mm'] : null,
                            'y_mm' => isset($p['y_mm']) && is_numeric($p['y_mm']) ? (float)$p['y_mm'] : null,
                        ];
                    }
                }
            }
        }

        // 6. Tamponnage PDF
        try {
            $pdfCertifiePath = $pdfService->certifyPdf(
                $originalAbsolute,
                $qrPng,
                qrSizeMm:    isset($validated['qr_size_mm']) ? (int)$validated['qr_size_mm'] : 25,
                qrPositions: $qrPositions,
            );
        } catch (\RuntimeException $e) {
            @unlink($originalAbsolute);
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // 7. Chemin relatif depuis storage/app/public/
        $storagePublic       = str_replace('\\', '/', storage_path('app/public'));
        $pdfCertifieRelative = ltrim(str_replace('\\', '/', str_replace($storagePublic, '', $pdfCertifiePath)), '/');

        // 8. Persistance
        $document = DB::transaction(function () use ($emetteur, $validated, $originalPath, $hash, $token, $pdfCertifieRelative, $qrPositions) {
            return Document::create([
                'emetteur_id'      => $emetteur->id,
                'titre'            => $validated['titre'],
                'type'             => $validated['type'],
                'fichier_original' => $originalPath,
                'hash_sha256'      => $hash,
                'qr_token'         => $token,
                'pdf_certifie'     => $pdfCertifieRelative,
                'statut'           => 'actif',
                'date_emission'    => $validated['date_emission'],
                'date_expiration'  => $validated['date_expiration'] ?? null,
                'qr_position_x'    => $qrPositions[1]['x_mm'] ?? null,
                'qr_position_y'    => $qrPositions[1]['y_mm'] ?? null,
            ]);
        });

        return response()->json($document->load('verifications'), 201);
    }

    /**
     * Retourne les dimensions (en mm) de chaque page d'un PDF uploadé.
     * POST /api/documents/page-dimensions
     */
    public function pageDimensions(Request $request): JsonResponse
    {
        $request->validate([
            'fichier' => ['required', 'file', 'mimetypes:application/pdf', 'max:20480'],
        ]);

        $path = $request->file('fichier')->getRealPath();

        try {
            $fpdi      = new \setasign\Fpdi\Fpdi();
            $pageCount = $fpdi->setSourceFile($path);

            $pages = [];
            for ($i = 1; $i <= $pageCount; $i++) {
                $tplId   = $fpdi->importPage($i);
                $size    = $fpdi->getTemplateSize($tplId);
                $pages[] = [
                    'page'        => $i,
                    'width_mm'    => round($size['width'],  2),
                    'height_mm'   => round($size['height'], 2),
                    'orientation' => $size['orientation'],
                ];
            }
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Impossible de lire le fichier PDF : ' . $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'total_pages' => $pageCount,
            'pages'       => $pages,
        ]);
    }

    /**
     * Liste les documents de l'émetteur connecté.
     * GET /api/documents
     */
    public function index(Request $request): JsonResponse
    {
        $documents = Document::where('emetteur_id', $request->user()->id)
            ->withCount('verifications')
            ->latest()
            ->get();

        return response()->json($documents);
    }

    /**
     * Télécharge le PDF certifié d'un document.
     * GET /api/documents/{document}/download
     */
    public function download(Request $request, Document $document)
    {
        $user = $request->user();

        if ($user->role !== 'admin' && $document->emetteur_id !== $user->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $relative = ltrim(str_replace('\\', '/', $document->pdf_certifie), '/');
        $path     = storage_path('app/public/' . $relative);

        if (!file_exists($path)) {
            return response()->json(['message' => 'Fichier introuvable.'], 404);
        }

        $safeTitle = preg_replace('/[^a-zA-Z0-9_\-\s]/', '_', $document->titre);

        return response()->download($path, $safeTitle . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Révoque un document avec un motif obligatoire.
     * PATCH /api/documents/{document}/revoke
     */
    public function revoke(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'admin' && $document->emetteur_id !== $user->id) {
            return response()->json(['message' => 'Vous n\'êtes pas autorisé à révoquer ce document.'], 403);
        }

        if ($document->statut === 'revoque') {
            return response()->json(['message' => 'Ce document est déjà révoqué.'], 422);
        }

        $validated = $request->validate([
            'motif_revocation' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $document->update([
            'statut'           => 'revoque',
            'motif_revocation' => $validated['motif_revocation'],
            'revoked_at'       => now(),
            'revoked_by'       => $user->id,
        ]);

        return response()->json([
            'message'  => 'Document révoqué avec succès.',
            'document' => $document->fresh(),
        ]);
    }
}
