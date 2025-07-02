import sys
import json
import numpy as np
import google.generativeai as genai

# --- THIS SCRIPT'S ONLY JOB IS TO RETURN A VECTOR ---

# --- YOUR CREDENTIALS ---
# This script still needs the key to talk to Google
GOOGLE_AI_API_KEY = "AIzaSyAWYls6qtmS07GJ02JwdPsv2IIOWm8luic"

# Configure the Google AI client
genai.configure(api_key=GOOGLE_AI_API_KEY)

def normalize_embedding(embedding):
    """Normalize the embedding to have a unit norm."""
    norm = np.linalg.norm(embedding)
    if norm == 0:
        return embedding
    return (embedding / norm).tolist() # Convert to a standard list for JSON

def get_query_embedding(text):
    """
    This is the same logic as your working Python script's query part.
    """
    try:
        # We will use the consistent 'retrieval_document' type that we know works in Python
        result = genai.embed_content(
            model="models/embedding-001", 
            content=text, 
            task_type="retrieval_document"
        )
        return result['embedding']
    except Exception as e:
        # Print errors to stderr so PHP doesn't read them as output
        print(f"Error: {e}", file=sys.stderr)
        return None

if __name__ == "__main__":
    # Check if text was passed as a command-line argument
    if len(sys.argv) > 1:
        input_text = sys.argv[1]
        
        # Get the raw embedding
        raw_embedding = get_query_embedding(input_text)
        
        if raw_embedding:
            # Normalize it, just like your query script does
            normalized_embedding = normalize_embedding(raw_embedding)
            
            # Print the final vector as a JSON string, which PHP can easily read
            print(json.dumps(normalized_embedding))
    else:
        print("Error: No input text provided.", file=sys.stderr)