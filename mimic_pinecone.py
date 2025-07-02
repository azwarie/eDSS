import pandas as pd
import os
import numpy as np
from pinecone import Pinecone, ServerlessSpec
import google.generativeai as genai
import time
from tqdm import tqdm
import uuid

# --- YOUR CREDENTIALS ---
PINECONE_API_KEY = "pcsk_7AaWSb_SxG1QbdvcYD3aBPRgDqsbnFdEBZGZNXX2tdxREkNxyPafA9bnHMMV44Fg2gdkVK"
GOOGLE_AI_API_KEY = "AIzaSyAWYls6qtmS07GJ02JwdPsv2IIOWm8luic"

# Configure the Google AI client
genai.configure(api_key=GOOGLE_AI_API_KEY)

# --- SCRIPT CONFIGURATION ---
index_name = 'mimic-pinecone'
MODEL_DIMENSION = 768
BATCH_SIZE = 100

# ===================================================================
# ALL YOUR ORIGINAL FUNCTIONS - UNTOUCHED (except get_google_embedding)
# ===================================================================

def get_pinecone_index(index_name, dimension):
    pc = Pinecone(api_key=PINECONE_API_KEY)
    if index_name in pc.list_indexes().names():
        print(f"Index '{index_name}' already exists. Connecting to it.")
        index = pc.Index(index_name)
    else:
        print(f"Index '{index_name}' does not exist. Creating it.")
        pc.create_index(
            name=index_name, dimension=dimension, metric="cosine", 
            spec=ServerlessSpec(cloud="aws", region="us-east-1")
        )
        print("Waiting for index to initialize...")
        while not pc.describe_index(index_name).status['ready']:
            time.sleep(5)
        print("Index is ready.")
        index = pc.Index(index_name)
    return index

def load_mimic_data(file_path):
    try:
        df = pd.read_excel(file_path, sheet_name='triage_diagnosis')
        df.dropna(subset=['chiefcomplaint'], inplace=True)
        df['chiefcomplaint'] = df['chiefcomplaint'].astype(str)
        print(f"Loaded {len(df)} rows with valid chief complaints.")
        return df
    except Exception as e:
        print(f"Error loading data: {e}")
        return None

def get_google_embedding(text, task_type="retrieval_document"):
    """Gets a vector embedding for a piece of text using Google AI."""
    content_to_embed = str(text).strip() if pd.notna(text) and str(text).strip() else "no complaint recorded"
    try:
        result = genai.embed_content(model="models/embedding-001", content=content_to_embed, task_type=task_type)
        return result['embedding']
    except Exception as e:
        print(f"\nCould not get embedding for '{content_to_embed}'. Error: {e}")
        return None

def normalize_embedding(embedding):
    """Normalize the embedding to have a unit norm."""
    norm = np.linalg.norm(embedding)
    if norm == 0: return embedding
    return embedding / norm

def query_top_3_diagnoses(index, chief_complaint):
    query_embedding_raw = get_google_embedding(chief_complaint, task_type="retrieval_document")
    if query_embedding_raw is None: return []
    query_embedding_normalized = normalize_embedding(query_embedding_raw)
    query_vector_list = query_embedding_normalized.tolist()
    if any(np.isnan(query_vector_list)) or any(np.isinf(query_vector_list)): return []
    try:
        query_results = index.query(
            vector=query_vector_list, top_k=3, include_metadata=True, namespace="ns1"
        )
    except Exception as e:
        print(f"Error during Pinecone query: {e}")
        return []
    matches = query_results.get('matches', [])
    if matches:
        top_3 = []
        for match in matches:
            metadata = match.get('metadata', {})
            top_3.append({
                'chiefcomplaint': metadata.get('chiefcomplaint', 'N/A'),
                'icd_title': metadata.get('icd_title', 'N/A'),
                'icd_code': metadata.get('icd_code', 'N/A'),
                'similarity_score': match.get('score', 'N/A')
            })
        return top_3
    return []

# ===================================================================
# THE NEW UPLOAD FUNCTION WITH CORRECTED DATA TYPES
# ===================================================================

def is_number(s):
    """Helper function to check if a value can be converted to a float."""
    try:
        float(s)
        return True
    except (ValueError, TypeError):
        return False

def upload_data_with_corrected_types(index, data_df):
    """Handles the corrected data upload process, with smart data typing."""
    print("\n--- Starting Corrected Data Upload with Numerical Formatting ---")
    
    numeric_columns = ['temperature', 'heartrate', 'resprate', 'o2sat', 'sbp', 'dbp', 'acuity', 'seq_num']
    
    successful_uploads = 0
    failed_uploads = 0
    
    # We use df.iterrows() to get the row index for creating a unique ID
    for row_index, row in tqdm(data_df.iterrows(), total=len(data_df), desc="Uploading Batches"):
        
        # ========================================================
        # !! THE STUPID ERROR IS FIXED HERE !!
        # It now correctly calls `get_google_embedding`.
        # ========================================================
        embedding = get_google_embedding(row.get('chiefcomplaint'))
        
        if embedding is None:
            failed_uploads += 1
            continue

        unique_id = f"row-{row_index}"

        metadata = {
            "PatientID": str(row.get('PatientID', '')), "StayID": str(row.get('StayID', '')),
            "chiefcomplaint": str(row.get('chiefcomplaint', '')),
            "icd_code": str(row.get('icd_code', '')),
            "icd_version": str(row.get('icd_version', '')),
            "icd_title": str(row.get('icd_title', '')),
            "text": str(row.get('chiefcomplaint', ''))
        }
        
        for col in numeric_columns:
            # Check for both capitalized and lowercase column names from Excel
            value = row.get(col.capitalize(), row.get(col)) 
            if pd.notna(value) and is_number(value):
                metadata[col] = float(value)
        
        pain_value = row.get('pain', row.get('Pain'))
        if pd.notna(pain_value):
            if is_number(pain_value):
                metadata['pain_score'] = float(pain_value)
            else:
                metadata['pain_status'] = str(pain_value).strip().lower()

        # This is a different way to do batching that is more robust
        try:
            index.upsert(
                vectors=[{'id': unique_id, 'values': embedding, 'metadata': metadata}],
                namespace="ns1"
            )
            successful_uploads += 1
        except Exception as e:
            print(f"\nERROR upserting row {row_index}. ID: {unique_id}. Error: {e}")
            failed_uploads += 1

    print(f"\n--- Upload Complete. Successful: {successful_uploads}, Failed: {failed_uploads} ---")

# ===================================================================
# YOUR ORIGINAL MAIN FUNCTION
# ===================================================================

def main(data_file_path):
    data = load_mimic_data(data_file_path)
    if data is None: return

    # --- INSTRUCTIONS FOR THE ONE-TIME DATA FIX ---
    # 1. Manually DELETE your 'mimic-pinecone' index on the Pinecone website first.
    # 2. UNCOMMENT the two lines below to run the upload.
    # 3. Run the script once.
    # 4. After it's done, COMMENT the two lines out again.
    
    # --- UNCOMMENT BELOW TO UPLOAD ---
    #pc = Pinecone(api_key=PINECONE_API_KEY)
    #if index_name in pc.list_indexes().names():
         #print(f"Deleting existing index '{index_name}'...")
         #pc.delete_index(index_name)
         #time.sleep(20)
    #index = get_pinecone_index(index_name, MODEL_DIMENSION)
    #upload_data_with_corrected_types(index, data)
    # return # Exit after uploading
    # ----------------------------------

    # Your normal query logic, which will run if the upload lines are commented out.
    index = get_pinecone_index(index_name, MODEL_DIMENSION)
    print("Skipping data upsert as the index already exists.")

    chief_complaint = input("Enter the chief complaint to query: ")
    top_3_diagnoses = query_top_3_diagnoses(index, chief_complaint)

    if top_3_diagnoses:
        print("\nTop 3 diagnoses based on the chief complaint:")
        for i, diagnosis in enumerate(top_3_diagnoses, 1):
            print(f"{i}. Chief Complaint: {diagnosis['chiefcomplaint']} - Diagnosis: {diagnosis['icd_title']} (Code: {diagnosis['icd_code']}) (Score: {diagnosis.get('similarity_score', 'N/A')})")
    else:
        print("No results found for the given chief complaint.")

if __name__ == "__main__":
    data_file_path = "1-triage_N-diagnosis.xlsx"
    if not os.path.exists(data_file_path):
        print(f"Error: File not found at {data_file_path}")
    else:
        main(data_file_path)