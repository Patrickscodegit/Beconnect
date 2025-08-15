<?php

namespace App\Http\Controllers;

use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FileController extends Controller
{
    public function __construct(
        private StorageService $storageService
    ) {}

    /**
     * Upload a CSV file for vehicle data import
     */
    public function uploadCsv(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'csv_file' => 'required|file|mimes:csv,txt|max:10240', // 10MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('csv_file');
            $path = $this->storageService->storeCsv($file);
            
            return response()->json([
                'success' => true,
                'message' => 'CSV file uploaded successfully',
                'data' => [
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'temporary_url' => $this->storageService->getTemporaryUrl($path, 60)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload vehicle images
     */
    public function uploadVehicleImage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|max:5120', // 5MB max
            'vin' => 'required|string|size:17'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('image');
            $vin = $request->input('vin');
            $path = $this->storageService->storeVehicleImage($file, $vin);
            
            return response()->json([
                'success' => true,
                'message' => 'Vehicle image uploaded successfully',
                'data' => [
                    'path' => $path,
                    'vin' => $vin,
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'temporary_url' => $this->storageService->getTemporaryUrl($path, 1440) // 24 hours
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload vehicle documents
     */
    public function uploadVehicleDocument(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'document' => 'required|file|mimes:pdf,doc,docx,txt|max:20480', // 20MB max
            'vin' => 'required|string|size:17'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('document');
            $vin = $request->input('vin');
            $path = $this->storageService->storeVehicleDocument($file, $vin);
            
            return response()->json([
                'success' => true,
                'message' => 'Vehicle document uploaded successfully',
                'data' => [
                    'path' => $path,
                    'vin' => $vin,
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'temporary_url' => $this->storageService->getTemporaryUrl($path, 1440) // 24 hours
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload document: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * List files in a directory
     */
    public function listFiles(Request $request): JsonResponse
    {
        $directory = $request->query('directory', '');
        
        try {
            $files = $this->storageService->files($directory);
            
            $fileData = array_map(function ($path) {
                return [
                    'path' => $path,
                    'name' => basename($path),
                    'size' => $this->storageService->size($path),
                    'last_modified' => date('Y-m-d H:i:s', $this->storageService->lastModified($path)),
                    'temporary_url' => $this->storageService->getTemporaryUrl($path, 60)
                ];
            }, $files);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'directory' => $directory ?: 'root',
                    'files' => $fileData,
                    'count' => count($fileData)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to list files: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a file
     */
    public function deleteFile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'path' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $path = $request->input('path');
            
            if (!$this->storageService->exists($path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found'
                ], 404);
            }

            $deleted = $this->storageService->delete($path);
            
            return response()->json([
                'success' => $deleted,
                'message' => $deleted ? 'File deleted successfully' : 'Failed to delete file'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete file: ' . $e->getMessage()
            ], 500);
        }
    }
}
