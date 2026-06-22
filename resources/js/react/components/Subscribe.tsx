import { useState } from 'react';
import type { FormEvent } from 'react';

const Subscribe: React.FC = () => {
  const [email, setEmail] = useState('');

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    // Handle subscription logic here
    console.log('Subscribed:', email);
    setEmail('');
  };

  return (
    <section className="py-16 bg-gray-100">
      <div className="container mx-auto px-4">
        <div className="max-w-xl mx-auto text-center">
          <p className="text-xl text-gray-700 mb-6">
            Get notified about new amazing products
          </p>
          <form onSubmit={handleSubmit}>
            <div className="flex flex-col sm:flex-row gap-3">
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="Your email here"
                required
                className="flex-1 px-6 py-3 rounded-full border border-gray-300 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
              />
              <button
                type="submit"
                className="px-8 py-3 bg-primary text-white font-semibold rounded-full hover:bg-primary-dark transition-colors duration-300 shadow-lg hover:shadow-xl"
              >
                Subscribe
              </button>
            </div>
          </form>
        </div>
      </div>
    </section>
  );
};

export default Subscribe;
